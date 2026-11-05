import { PRODUCT_PRICE_USD, ORDER_EXPIRY_MINUTES, ALLOWED_UNDERPAYMENT_PERCENTAGE } from '../_lib/config.js';
import { AddressPoolManager } from '../_lib/pool.js';

async function fetchBtcUsdRate() {
    try {
        const res = await fetch('https://mempool.space/api/v1/prices', { headers: { 'User-Agent': 'Cloudflare-Worker' } });
        if (res.ok) {
            const data = await res.json();
            if (data && data.USD && Number(data.USD) > 0) {
                return Number(data.USD);
            }
        }
    } catch (e) {}

    try {
        const res = await fetch('https://api.coinbase.com/v2/prices/BTC-USD/spot', { headers: { 'User-Agent': 'Cloudflare-Worker' } });
        if (res.ok) {
            const data = await res.json();
            if (data && data.data && data.data.amount && Number(data.data.amount) > 0) {
                return Number(data.data.amount);
            }
        }
    } catch (e) {}

    return 78000.00;
}

export async function onRequestPost(context) {
    const corsHeaders = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        'Content-Type': 'application/json'
    };

    try {
        const body = await context.request.json().catch(() => ({}));
        const email = (body.email || '').trim();

        if (!email || !email.includes('@')) {
            return new Response(JSON.stringify({ success: false, message: 'Please provide a valid email address.' }), {
                status: 400,
                headers: corsHeaders
            });
        }

        // Strict Server-Side Pricing (Bug #5 Fix)
        const isSample = body.sample === true || body.type === 'sample';
        const priceUsd = isSample ? 1.00 : PRODUCT_PRICE_USD;
        const productName = isSample ? 'CTOOZ $1 Test Verification' : 'CTOOZ Lifetime License';

        // Order ID
        const randomBytes = new Uint8Array(5);
        crypto.getRandomValues(randomBytes);
        const randomHex = Array.from(randomBytes).map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
        const orderId = `CTZ-${randomHex}`;

        // Real-time BTC Rate
        const rate = await fetchBtcUsdRate();
        const expectedSats = Math.round((priceUsd / rate) * 100000000);
        const expectedBtc = (expectedSats / 100000000).toFixed(8);

        // Tolerance calculation
        const tolerancePct = ALLOWED_UNDERPAYMENT_PERCENTAGE;
        let minAcceptableSats = Math.round(expectedSats * (1 - (tolerancePct / 100)));
        if (priceUsd <= 1.00 && expectedSats <= 2500) {
            minAcceptableSats = Math.min(minAcceptableSats, 1000);
        }

        // Acquire Bitcoin address from D1 Pool (Reuses expired addresses)
        const poolItem = await AddressPoolManager.acquireAddress(context.env.DB, orderId, ORDER_EXPIRY_MINUTES);
        const btcAddress = poolItem.address;
        const derivationIndex = poolItem.index;
        const expiresAt = poolItem.expiresAt;

        // Insert Order into Cloudflare D1
        await context.env.DB.prepare(`
            INSERT INTO btc_orders (order_id, buyer_email, product_name, price_usd, expected_sats, expected_btc, btc_rate_usd, bitcoin_address, derivation_index, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        `).bind(
            orderId,
            email,
            productName,
            priceUsd,
            expectedSats,
            parseFloat(expectedBtc),
            rate,
            btcAddress,
            derivationIndex,
            expiresAt
        ).run();

        const paymentLink = `bitcoin:${btcAddress}?amount=${expectedBtc}`;
        const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(paymentLink)}`;

        return new Response(JSON.stringify({
            success: true,
            orderId: orderId,
            productName: productName,
            priceUsd: priceUsd,
            btcAmount: expectedBtc,
            sats: expectedSats,
            minimumAcceptableSats: minAcceptableSats,
            tolerancePercentage: tolerancePct,
            rate: rate,
            address: btcAddress,
            derivationIndex: derivationIndex,
            isReused: poolItem.reused,
            paymentLink: paymentLink,
            qrCodeUrl: qrCodeUrl,
            expiresAt: expiresAt,
            expiresInSeconds: ORDER_EXPIRY_MINUTES * 60
        }), {
            status: 200,
            headers: corsHeaders
        });

    } catch (e) {
        return new Response(JSON.stringify({ success: false, message: `Checkout error: ${e.message}` }), {
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
