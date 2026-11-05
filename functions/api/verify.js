import { ALLOWED_UNDERPAYMENT_PERCENTAGE, MEMPOOL_API_BASE, maskEmail, sendOrderEmail } from '../_lib/config.js';
import { AddressPoolManager, ensureDbInitialized } from '../_lib/pool.js';

async function fetchMempoolTxid(btcAddress) {
    try {
        const res = await fetch(`${MEMPOOL_API_BASE}/address/${encodeURIComponent(btcAddress)}/txs`, {
            headers: { 'User-Agent': 'Cloudflare-Worker' }
        });
        if (res.ok) {
            const txs = await res.json();
            if (Array.isArray(txs) && txs.length > 0 && txs[0].txid) {
                return txs[0].txid;
            }
        }
    } catch (e) {}
    return null;
}

export async function onRequestGet(context) {
    const corsHeaders = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        'Content-Type': 'application/json'
    };

    const url = new URL(context.request.url);
    const params = url.searchParams;

    let query = (params.get('q') || params.get('query') || params.get('txInput') || '').trim();
    let orderId = (params.get('order_id') || params.get('id') || '').trim();
    let address = (params.get('address') || '').trim();
    let txid = (params.get('txid') || params.get('hash') || '').trim();

    if (query) {
        if (query.startsWith('CTZ-')) {
            orderId = query;
        } else if (query.length === 64 && /^[0-9a-fA-F]+$/.test(query)) {
            txid = query;
        } else {
            address = query;
        }
    }

    if (orderId && address && orderId === address) {
        if (orderId.startsWith('CTZ-')) {
            address = '';
        } else if (orderId.length === 64 && /^[0-9a-fA-F]+$/.test(orderId)) {
            txid = orderId;
            orderId = '';
            address = '';
        } else {
            orderId = '';
        }
    }

    if (!orderId && !address && !txid) {
        return new Response(JSON.stringify({ error: 'Missing order_id, address, or txid parameter' }), {
            status: 400,
            headers: corsHeaders
        });
    }

    try {
        const db = context.env.DB;
        await ensureDbInitialized(db);

        // Query Order
        let order = null;
        if (orderId) {
            order = await db.prepare("SELECT * FROM btc_orders WHERE order_id = ? LIMIT 1").bind(orderId).first();
        }
        if (!order && txid) {
            order = await db.prepare("SELECT * FROM btc_orders WHERE txid = ? ORDER BY id DESC LIMIT 1").bind(txid).first();
        }
        if (!order && address) {
            order = await db.prepare("SELECT * FROM btc_orders WHERE bitcoin_address = ? ORDER BY id DESC LIMIT 1").bind(address).first();
        }

        if (!order) {
            return new Response(JSON.stringify({ error: 'Order not found' }), {
                status: 404,
                headers: corsHeaders
            });
        }

        const expectedSats = Number(order.expected_sats);
        const priceUsd = Number(order.price_usd);
        const btcAddress = order.bitcoin_address;

        // Try fetching real blockchain TxID if not yet stored
        if (!order.txid) {
            const realTxid = await fetchMempoolTxid(btcAddress);
            if (realTxid) {
                order.txid = realTxid;
                try {
                    await db.prepare("UPDATE btc_orders SET txid = ? WHERE id = ?").bind(realTxid, order.id).run();
                } catch (e) {}
            }
        }
        let txHash = order.txid || order.order_id;

        // 1.5% Fee Tolerance & Minimum Acceptable Sats
        const tolerancePercentage = ALLOWED_UNDERPAYMENT_PERCENTAGE;
        let minimumAcceptableSats = Math.round(expectedSats * (1 - (tolerancePercentage / 100)));
        if (priceUsd <= 1.00 && expectedSats <= 2500) {
            minimumAcceptableSats = Math.min(minimumAcceptableSats, 1000);
        }

        // 1. Order already confirmed Paid
        if (order.status === 'paid') {
            const rec = Number(order.received_sats);
            const isUnder = (rec < expectedSats);
            const isOver = (rec > expectedSats);

            // Atomic Lock for Email
            if (order.email_sent === 0 && order.buyer_email) {
                const lockResult = await db.prepare("UPDATE btc_orders SET email_sent = 1 WHERE id = ? AND email_sent = 0")
                    .bind(order.id)
                    .run();
                if (lockResult.meta.changes > 0) {
                    await sendOrderEmail(context.env, order.buyer_email, order.order_id, btcAddress, rec, order.product_name, txHash);
                }
            }

            let msg = 'Payment confirmed on Bitcoin blockchain!';
            if (isUnder) {
                msg = 'Payment detected with slight fee deduction, successfully processing your order...';
            } else if (isOver) {
                msg = 'Payment verified (overpayment received)! Successfully processing your order...';
            }

            return new Response(JSON.stringify({
                status: 'paid',
                settled: true,
                orderId: order.order_id,
                address: order.bitcoin_address,
                buyerEmail: maskEmail(order.buyer_email),
                txid: txHash,
                emailSent: true,
                expectedSats: expectedSats,
                receivedSats: rec,
                minimumAcceptableSats: minimumAcceptableSats,
                tolerancePercentage: tolerancePercentage,
                underpayment_tolerated: isUnder,
                isUnderpaidTolerated: isUnder,
                isOverpaid: isOver,
                message: msg,
                paidAt: order.paid_at,
                redirect: 'success'
            }), { status: 200, headers: corsHeaders });
        }

        // 2. Query Mempool.space API
        let mempoolData = {};
        try {
            const mRes = await fetch(`${MEMPOOL_API_BASE}/address/${encodeURIComponent(btcAddress)}`, {
                headers: { 'User-Agent': 'Cloudflare-Worker' }
            });
            if (mRes.ok) {
                mempoolData = await mRes.json();
            }
        } catch (e) {}

        const chainSats = Number(mempoolData?.chain_stats?.funded_txo_sum || 0);
        const mempoolSats = Number(mempoolData?.mempool_stats?.funded_txo_sum || 0);
        const totalReceivedSats = chainSats + mempoolSats;

        const now = new Date();
        const nowIso = now.toISOString().replace('T', ' ').substring(0, 19);
        const isExpired = now.getTime() > new Date(order.expires_at).getTime();

        // 3. Payment Verified (Full, Overpaid, or within 1.5% Underpayment Tolerance)
        if (totalReceivedSats >= minimumAcceptableSats) {
            const isUnderpaidTolerated = (totalReceivedSats < expectedSats);
            const isOverpaid = (totalReceivedSats > expectedSats);

            let msg = 'Payment confirmed on Bitcoin blockchain!';
            if (isUnderpaidTolerated) {
                msg = 'Payment detected with slight fee deduction, successfully processing your order...';
            } else if (isOverpaid) {
                msg = 'Payment verified (overpayment received)! Successfully processing your order...';
            }

            if (txHash === order.order_id) {
                const realTxid = await fetchMempoolTxid(btcAddress);
                if (realTxid) txHash = realTxid;
            }

            // Update DB to paid
            await db.prepare(`
                UPDATE btc_orders 
                SET status = 'paid', received_sats = ?, paid_at = ?, txid = ?
                WHERE id = ?
            `).bind(totalReceivedSats, nowIso, txHash, order.id).run();

            // Permanently mark address as used in pool
            await AddressPoolManager.markUsed(db, btcAddress);

            // Atomic Lock for Email
            const lockResult = await db.prepare("UPDATE btc_orders SET email_sent = 1 WHERE id = ? AND email_sent = 0")
                .bind(order.id)
                .run();
            if (lockResult.meta.changes > 0) {
                await sendOrderEmail(context.env, order.buyer_email, order.order_id, btcAddress, totalReceivedSats, order.product_name, txHash);
            }

            return new Response(JSON.stringify({
                status: 'paid',
                settled: true,
                orderId: order.order_id,
                address: btcAddress,
                buyerEmail: maskEmail(order.buyer_email),
                txid: txHash,
                emailSent: true,
                expectedSats: expectedSats,
                receivedSats: totalReceivedSats,
                minimumAcceptableSats: minimumAcceptableSats,
                tolerancePercentage: tolerancePercentage,
                underpayment_tolerated: isUnderpaidTolerated,
                isUnderpaidTolerated: isUnderpaidTolerated,
                isOverpaid: isOverpaid,
                isMempoolZeroConf: (chainSats < minimumAcceptableSats),
                message: msg,
                paidAt: nowIso,
                redirect: 'success'
            }), { status: 200, headers: corsHeaders });
        }

        // 4. Partial Payment
        if (totalReceivedSats > 0) {
            // Retire address in pool so it is NEVER reused for another customer!
            await AddressPoolManager.markUsed(db, btcAddress);

            await db.prepare("UPDATE btc_orders SET received_sats = ?, txid = ? WHERE id = ?")
                .bind(totalReceivedSats, txHash, order.id)
                .run();

            const dueSats = expectedSats - totalReceivedSats;

            return new Response(JSON.stringify({
                status: 'partial',
                settled: false,
                orderId: order.order_id,
                address: btcAddress,
                buyerEmail: maskEmail(order.buyer_email),
                txid: txHash,
                expectedSats: expectedSats,
                minimumAcceptableSats: minimumAcceptableSats,
                tolerancePercentage: tolerancePercentage,
                receivedSats: totalReceivedSats,
                dueSats: dueSats,
                underpayment_tolerated: false,
                isUnderpaidTolerated: false,
                isOverpaid: false,
                message: `Partial payment received (${totalReceivedSats} Sats). Still due: ${dueSats} Sats.`
            }), { status: 200, headers: corsHeaders });
        }

        // 5. Expired
        if (isExpired) {
            return new Response(JSON.stringify({
                status: 'expired',
                settled: false,
                orderId: order.order_id,
                address: btcAddress,
                buyerEmail: maskEmail(order.buyer_email),
                txid: txHash,
                expectedSats: expectedSats,
                receivedSats: 0,
                message: 'Order window expired. Please generate a new invoice.'
            }), { status: 200, headers: corsHeaders });
        }

        // 6. Pending
        return new Response(JSON.stringify({
            status: 'pending',
            settled: false,
            orderId: order.order_id,
            address: btcAddress,
            buyerEmail: maskEmail(order.buyer_email),
            txid: txHash,
            expectedSats: expectedSats,
            minimumAcceptableSats: minimumAcceptableSats,
            receivedSats: 0,
            expiresAt: order.expires_at
        }), { status: 200, headers: corsHeaders });

    } catch (e) {
        return new Response(JSON.stringify({ error: `Verification error: ${e.message}` }), {
            status: 500,
            headers: corsHeaders
        });
    }
}

export async function onRequestOptions() {
    return new Response(null, {
        status: 204,
        headers: {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type, Authorization'
        }
    });
}
