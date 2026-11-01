import os
import sys
import time
import re
import json
import urllib.request
import urllib.error
import subprocess
from urllib.parse import urlparse, parse_qs
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler

PORT = 8080
DIRECTORY = os.path.abspath(os.path.dirname(__file__))

BTCPAY_HOST = "https://mainnet.demo.btcpayserver.org"
BTCPAY_API_KEY = "ff45b9924d037a80dfceb0a752204cfce7e782cd"
BTCPAY_STORE_ID = "HxEKoDq5NDUA5YwqUYv1RsQGQs1D77cZYd5o1JAtFu9r"

RESEND_API_KEY = "re_ByLWgbEk_PpKVYAhqqnBfEQ1wjnjeitgv"
RESEND_FROM    = "Ctooz Support <support@ctooz.com>"
DOWNLOAD_LINK  = "https://ctooz.com"

INVOICES_DB = {}

def trigger_resend_email(to_email, invoice_id, tx_hash=None):
    if not tx_hash:
        tx_hash = invoice_id
    if not to_email or '@' not in to_email:
        return False, "Invalid email address"

    html_body = f"""<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f9f9f9;margin:0;padding:0;">
  <div style="max-width:560px;margin:40px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    <div style="background:#1a202c;padding:28px 32px;">
      <h1 style="color:#ffffff;font-size:20px;margin:0;letter-spacing:1px;">CTOOZ</h1>
    </div>
    <div style="padding:32px;">
      <h2 style="color:#1a202c;font-size:18px;margin-top:0;">Transaction Confirmed - Your Download Link and Details</h2>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">Dear Partner,</p>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">
        Thank you for your transaction. We have successfully processed your request.
      </p>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">
        You can download your document or digital asset directly by clicking on the link below:
      </p>
      <div style="text-align:center;margin:24px 0;">
        <a href="{DOWNLOAD_LINK}" style="background:#D9540D;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-weight:bold;font-size:14px;">
          Click here to download (ctooz.com)
        </a>
      </div>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">For your reference, here are your transaction details:</p>
      <div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:16px 0;">
        <p style="margin:0;font-size:13px;color:#4a5568;">
          <strong>Transaction Hash:</strong><br>
          <span style="font-family:monospace;color:#1a202c;word-break:break-all;">{tx_hash}</span>
        </p>
      </div>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">
        Please save this transaction hash safely. If you need to access your files or download them again
        in the future, you can use this specific hash code on our portal to retrieve your download securely.
      </p>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;">
        If you face any issues while downloading or have any queries regarding your transaction,
        please feel free to reply to this email.
      </p>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;margin-bottom:0;">
        Best regards,<br>
        <strong>Support Team</strong><br>
        Ctooz
      </p>
    </div>
    <div style="background:#f7fafc;padding:16px 32px;text-align:center;">
      <p style="margin:0;font-size:11px;color:#a0aec0;">© 2024 CTOOZ. All rights reserved.</p>
    </div>
  </div>
</body>
</html>"""

    payload = {
        "from": RESEND_FROM,
        "to": [to_email],
        "subject": "Transaction Confirmed - Your Download Link and Details",
        "html": html_body
    }

    try:
        req = urllib.request.Request(
            "https://api.resend.com/emails",
            data=json.dumps(payload).encode('utf-8'),
            headers={
                "Authorization": f"Bearer {RESEND_API_KEY}",
                "Content-Type": "application/json",
                "User-Agent": "Mozilla/5.0 (compatible; Ctooz/1.0)"
            }
        )
        with urllib.request.urlopen(req, timeout=15) as res:
            resp_data = json.loads(res.read())
            print(f"[EMAIL SUCCESS] Sent to {to_email}, Resend ID: {resp_data.get('id')}")
            return True, resp_data.get("id")
    except urllib.error.HTTPError as e:
        err_body = e.read().decode('utf-8', errors='replace')
        print(f"[EMAIL ERROR] HTTP {e.code}: {err_body}")
        return False, f"Resend {e.code}: {err_body}"
    except Exception as e:
        print(f"[EMAIL ERROR] {e}")
        return False, str(e)

def get_base_url():
    live_file = os.path.join(DIRECTORY, 'live_url.txt')
    if os.path.exists(live_file):
        try:
            with open(live_file, 'r') as f:
                u = f.read().strip()
                if u.startswith('http'):
                    return u
        except Exception:
            pass
    return f"http://localhost:{PORT}"

class FastHTTPRequestHandler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

    def send_cors_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_cors_headers()
        self.end_headers()

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == '/api/invoice-status':
            query = parse_qs(parsed.query)
            inv_id = query.get('id', [''])[0]
            if not inv_id:
                self.send_json_response({"error": "Missing id parameter"}, 400)
                return
            try:
                req = urllib.request.Request(
                    f"{BTCPAY_HOST}/api/v1/stores/{BTCPAY_STORE_ID}/invoices/{inv_id}",
                    headers={"Authorization": f"token {BTCPAY_API_KEY}"}
                )
                with urllib.request.urlopen(req, timeout=10) as res:
                    inv = json.loads(res.read())
                    settled = inv.get("status") in ["Settled", "Complete", "Paid"]
                    if settled:
                        inv_info = INVOICES_DB.get(inv_id)
                        buyer_email = (inv_info.get("email") if inv_info else None) or inv.get("metadata", {}).get("buyerEmail")
                        if buyer_email:
                            if not inv_info:
                                inv_info = {"email": buyer_email, "email_sent": False}
                                INVOICES_DB[inv_id] = inv_info
                            if not inv_info.get("email_sent"):
                                ok, _ = trigger_resend_email(buyer_email, inv_id)
                                if ok:
                                    inv_info["email_sent"] = True

                    self.send_json_response({
                        "status": inv.get("status"),
                        "additionalStatus": inv.get("additionalStatus"),
                        "settled": settled,
                        "checkoutLink": inv.get("checkoutLink")
                    })
            except Exception as e:
                self.send_json_response({"error": str(e)}, 500)
            return

        # Clean URLs fallback for local development
        clean_path = parsed.path.strip('/')
        if clean_path:
            candidate = os.path.join(DIRECTORY, clean_path + '.html')
            if os.path.exists(candidate):
                self.path = '/' + clean_path + '.html'

        super().do_GET()

    def do_POST(self):
        parsed = urlparse(self.path)
        content_len = int(self.headers.get('Content-Length', 0))
        post_body = self.rfile.read(content_len).decode('utf-8') if content_len else "{}"
        try:
            body = json.loads(post_body)
        except Exception:
            body = {}

        if parsed.path == '/api/create-invoice':
            email = body.get('email', 'customer@ctooz.com')
            amount = body.get('amount', '499')
            currency = body.get('currency', 'USD')
            redirect_url = body.get('redirectUrl') or f"{get_base_url()}/success"

            payload = {
                "amount": str(amount),
                "currency": currency,
                "metadata": {
                    "orderId": f"CTOOZ-{int(time.time())}",
                    "itemDesc": "CTOOZ Complete Software License (Lifetime)",
                    "buyerEmail": email
                },
                "checkout": {
                    "redirectURL": redirect_url
                }
            }

            try:
                # 1. Create Invoice on BTCPay Server
                req = urllib.request.Request(
                    f"{BTCPAY_HOST}/api/v1/stores/{BTCPAY_STORE_ID}/invoices",
                    data=json.dumps(payload).encode('utf-8'),
                    headers={
                        "Authorization": f"token {BTCPAY_API_KEY}",
                        "Content-Type": "application/json"
                    }
                )
                with urllib.request.urlopen(req, timeout=10) as res:
                    inv = json.loads(res.read())

                inv_id = inv['id']
                INVOICES_DB[inv_id] = {"email": email, "email_sent": False}

                # 2. Fetch payment method (BTC destination, due amount, rate)
                req_pm = urllib.request.Request(
                    f"{BTCPAY_HOST}/api/v1/stores/{BTCPAY_STORE_ID}/invoices/{inv_id}/payment-methods",
                    headers={"Authorization": f"token {BTCPAY_API_KEY}"}
                )
                with urllib.request.urlopen(req_pm, timeout=10) as res_pm:
                    pm_list = json.loads(res_pm.read())

                pm = pm_list[0] if pm_list else {}

                self.send_json_response({
                    "success": True,
                    "invoiceId": inv_id,
                    "checkoutLink": inv.get("checkoutLink"),
                    "destination": pm.get("destination", "bc1qh9zgq5cr8q5qfmcpfkyr29n0vwx6w6xnax2gr9"),
                    "btcAmount": pm.get("amount", "0.0065"),
                    "paymentLink": pm.get("paymentLink", f"bitcoin:{pm.get('destination')}?amount={pm.get('amount')}"),
                    "rate": pm.get("rate", "")
                })
            except Exception as e:
                self.send_json_response({"success": False, "message": str(e)}, 500)
            return

        elif parsed.path == '/api/simulate-payment':
            inv_id = body.get('invoiceId')
            if not inv_id:
                self.send_json_response({'success': False, 'message': 'Missing invoiceId'}, 400)
                return

            try:
                # Update invoice status to Settled on BTCPay Server (Sandbox simulation)
                req = urllib.request.Request(
                    f"{BTCPAY_HOST}/api/v1/stores/{BTCPAY_STORE_ID}/invoices/{inv_id}/status",
                    data=json.dumps({"status": "Settled"}).encode('utf-8'),
                    headers={
                        "Authorization": f"token {BTCPAY_API_KEY}",
                        "Content-Type": "application/json"
                    }
                )
                with urllib.request.urlopen(req, timeout=10) as res:
                    res_data = json.loads(res.read())

                # Automatically trigger confirmation email on simulation!
                inv_info = INVOICES_DB.get(inv_id)
                to_email = body.get('email') or (inv_info.get('email') if inv_info else None)
                if to_email:
                    if not inv_info:
                        inv_info = {"email": to_email, "email_sent": False}
                        INVOICES_DB[inv_id] = inv_info
                    if not inv_info.get("email_sent"):
                        ok, _ = trigger_resend_email(to_email, inv_id)
                        if ok:
                            inv_info["email_sent"] = True

                self.send_json_response({
                    "success": True,
                    "status": "Settled",
                    "additionalStatus": res_data.get("additionalStatus"),
                    "redirectUrl": "success"
                })
            except Exception as e:
                self.send_json_response({"success": False, "message": str(e)}, 500)
            return

        elif parsed.path == '/api/send-confirmation-email':
            to_email   = body.get('email', '')
            invoice_id = body.get('invoiceId', 'N/A')
            tx_hash    = body.get('txHash', invoice_id)

            if not to_email or '@' not in to_email:
                self.send_json_response({"success": False, "message": "Invalid email address"}, 400)
                return

            inv_info = INVOICES_DB.get(invoice_id)
            if inv_info and inv_info.get("email_sent"):
                self.send_json_response({"success": True, "alreadySent": True})
                return

            ok, res = trigger_resend_email(to_email, invoice_id, tx_hash)
            if ok:
                if not inv_info:
                    INVOICES_DB[invoice_id] = {"email": to_email, "email_sent": True}
                else:
                    inv_info["email_sent"] = True
                self.send_json_response({"success": True, "emailId": res})
            else:
                self.send_json_response({"success": False, "message": res}, 500)
            return

        self.send_response(404)
        self.end_headers()

    def send_json_response(self, data, code=200):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(json.dumps(data).encode('utf-8'))

    def end_headers(self):
        # Set Cache-Control for static assets
        path = self.translate_path(self.path)
        if any(path.endswith(ext) for ext in ('.png', '.webp', '.jpg', '.jpeg', '.svg', '.css', '.js', '.ico')):
            self.send_header("Cache-Control", "public, max-age=86400")
        else:
            self.send_header("Cache-Control", "no-cache")
        super().end_headers()

    def log_message(self, format, *args):
        sys.stdout.write(f"[{time.strftime('%X')}] {format % args}\n")
        sys.stdout.flush()

def run():
    server = ThreadingHTTPServer(('127.0.0.1', PORT), FastHTTPRequestHandler)
    print(f"Local server started on http://127.0.0.1:{PORT}")

    cf_bin = os.path.join(DIRECTORY, 'cloudflared.exe')
    if not os.path.exists(cf_bin):
        print(f"Error: {cf_bin} not found")
        sys.exit(1)

    print("Connecting Cloudflare edge tunnel...")
    tunnel_proc = subprocess.Popen(
        [cf_bin, 'tunnel', '--url', f"http://127.0.0.1:{PORT}"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        bufsize=1
    )

    tunnel_url = None
    start_time = time.time()
    while time.time() - start_time < 25:
      line = tunnel_proc.stderr.readline()
      if not line:
        continue
      if 'trycloudflare.com' in line:
        match = re.search(r'https://[a-zA-Z0-9-]+\.trycloudflare\.com', line)
        if match:
          tunnel_url = match.group(0)
          break

    if tunnel_url:
      print(f"\n========================================")
      print(f"LIVE PUBLIC URL: {tunnel_url}")
      print(f"========================================\n")
      with open(os.path.join(DIRECTORY, 'live_url.txt'), 'w') as f:
        f.write(tunnel_url)
    else:
      print("Warning: Could not capture Cloudflare URL from output")

    try:
      server.serve_forever()
    except KeyboardInterrupt:
      pass
    finally:
      server.server_close()
      tunnel_proc.terminate()


if __name__ == '__main__':
  run()
