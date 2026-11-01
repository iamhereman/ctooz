# CTOOZ - Complete Software & Document Platform

Official repository for CTOOZ website with BTCPay Server Integration.

## Features
- Full responsive landing page (`index.html`, `features.html`, `about.html`, `license.html`, `success.html`)
- Integrated BTCPay Server payment gateway (Bitcoin on-chain)
- **Sandbox Testing Mode** enabled for local development & checkout verification
- Automated transaction confirmation and delivery emails via Resend API
- Local development server (`server.py`) with Cloudflare Tunnel support
- Production PHP API backend (`api.php`) for Apache / LiteSpeed hosting

## Getting Started (Local Development)

### Prerequisites
- Python 3.8+

### Run Locally
```bash
python server.py
```
Open [http://localhost:8080](http://localhost:8080) in your browser.

## Sandbox Testing
1. Navigate to `/license`
2. Enter your email and click **Purchase Now**
3. In the checkout modal, click **Simulate Sandbox Payment**
4. Payment will settle instantly and trigger confirmation flow.
