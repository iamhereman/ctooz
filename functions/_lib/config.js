export const BTC_XPUB = 'xpub6BemYiVNp19ZzLHf9jYggapPP7UeApuWES8dnHaH8R377D9Mm7NEgwcxHV9FwR1zNQQPKURXn8XTZv5Wzvqxut2gnnTXq3Wj5fE2PGsBxGc';
export const PRODUCT_PRICE_USD = 499.00;
export const ORDER_EXPIRY_MINUTES = 30;
export const ALLOWED_UNDERPAYMENT_PERCENTAGE = 1.5;
export const MEMPOOL_API_BASE = 'https://mempool.space/api';

/**
 * Masks buyer email for privacy protection in public responses
 * e.g. "iamhereman@gmail.com" -> "i****n@gmail.com"
 */
export function maskEmail(email) {
    if (!email || typeof email !== 'string' || !email.includes('@')) {
        return '***@***.com';
    }
    const [name, domain] = email.split('@');
    const len = name.length;
    let maskedName = '';
    if (len <= 2) {
        maskedName = name[0] + '***';
    } else {
        maskedName = name[0] + '*'.repeat(Math.min(4, len - 2)) + name.slice(-1);
    }
    return `${maskedName}@${domain}`;
}

/**
 * Sends a confirmation email to the buyer via Resend API
 */
export async function sendOrderEmail(env, buyerEmail, orderId, btcAddress, receivedSats, productName = 'CTOOZ License', txHash = null) {
    if (!buyerEmail || !buyerEmail.includes('@')) return false;

    const apiKey = env.RESEND_API_KEY;
    if (!apiKey) {
        console.warn('RESEND_API_KEY not configured. Email skipped.');
        return false;
    }

    const safeTxHash = txHash || orderId;
    const subject = `Transaction Confirmed - Your ${productName} Details & Access`;

    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body, table, td, p, a, div {
    margin: 0; padding: 0; border: 0; font-size: 100%;
    font-family: Helvetica Neue, Helvetica, Arial, sans-serif;
    line-height: 1.6;
  }
  body { background-color: #f4f6f9; padding: 24px 12px; }
  .email-container {
    max-width: 580px; margin: 0 auto; background: #ffffff;
    border-radius: 14px; overflow: hidden;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.10); border: 1px solid #e9edf2;
  }
  .header { background: #D9540D; border-bottom: 4px solid #B84308; padding: 26px 30px; }
  .header h1 {
    color: #ffffff; font-size: 23px; font-weight: 800;
    letter-spacing: 4px; margin: 0; text-transform: uppercase;
  }
  .header .tagline {
    color: rgba(255,255,255,0.85); font-size: 10px; font-weight: 600;
    letter-spacing: 2px; text-transform: uppercase; margin-top: 5px;
  }
  .content { padding: 34px 30px 30px 30px; }
  .content h2 { color: #1a202c; font-size: 21px; font-weight: 700; margin: 0 0 18px 0; }
  .content p { color: #4a5568; font-size: 15px; margin: 0 0 18px 0; line-height: 1.75; }
  .hash-box {
    background: #f7fafc; border: 1px solid #e2e8f0; border-left: 4px solid #D9540D;
    border-radius: 8px; padding: 16px 18px; margin: 22px 0 26px 0; word-break: break-all;
  }
  .hash-box .label { font-size: 12px; font-weight: 700; color: #2d3748; text-transform: uppercase; margin-bottom: 7px; display: block; }
  .hash-box .value { font-family: Courier New, Courier, monospace; font-size: 14px; color: #1a202c; }
  .note {
    background: #fffaf0; border-left: 4px solid #DD6B20; padding: 15px 18px;
    margin: 22px 0; border-radius: 0 8px 8px 0; font-size: 14px; color: #7b341e;
  }
  .divider { height: 1px; background: #e2e8f0; margin: 26px 0; }
  .footer { background: #f7fafc; padding: 18px 30px; text-align: center; border-top: 1px solid #e2e8f0; }
  .footer p { font-size: 12px; color: #a0aec0; margin: 0; }
</style>
</head>
<body>
  <div class="email-container">
    <div class="header">
      <h1>CTOOZ</h1>
      <div class="tagline">Secure Digital Delivery</div>
    </div>
    <div class="content">
      <h2>Transaction Confirmed</h2>
      <p>Dear Partner,</p>
      <p>Thank you for your transaction. We are pleased to confirm that your payment has been successfully processed.</p>

      <div class="hash-box">
        <span class="label">Transaction Hash</span>
        <span class="value">${safeTxHash}</span>
      </div>

      <p>We would like to inform you that our servers have been temporarily seized by the authorities (vvv and ccc). As a result, our services are currently undergoing restoration.</p>

      <p>Our technical team is working to restore the infrastructure as soon as possible. Once the servers are operational, or if an alternative delivery option becomes available sooner, we will promptly provide you with a secure download link for your software.</p>

      <div class="note">
        <strong>Important:</strong> Please keep your transaction hash safe and secure. It will serve as your transaction reference and may be required to retrieve your files through our portal once the service is restored.
      </div>

      <p>We sincerely apologize for the inconvenience and appreciate your patience and understanding during this period.</p>

      <div class="divider"></div>

      <p style="margin-bottom:0;">
        Best regards,<br>
        <strong>CTOOZ Support Team</strong><br>
        Ctooz
      </p>
    </div>
    <div class="footer">
      <p>© 2024 CTOOZ. All rights reserved.</p>
    </div>
  </div>
</body>
</html>`;

    try {
        const res = await fetch('https://api.resend.com/emails', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                from: 'CTOOZ Support <support@ctooz.com>',
                to: [buyerEmail],
                subject: subject,
                html: html
            })
        });
        return res.ok;
    } catch (e) {
        console.error('Email send error:', e);
        return false;
    }
}
