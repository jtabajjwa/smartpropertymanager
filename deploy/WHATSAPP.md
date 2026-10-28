# Tenant WhatsApp bot: setup

Tenants message Primora's WhatsApp number and get a menu: balance, invoices
(as PDFs), paying by mobile money, payments, reporting and following issues,
and their tenancy details. It uses **Meta's WhatsApp Cloud API** directly.

The code is in `app/WhatsApp/` (`TenantBot`, `CloudApi`, `Input`), the
webhook in `app/Http/Controllers/Api/WhatsAppWebhookController.php`, and
messages are handled by the queue worker (`HandleWhatsAppMessage`).

You can build and test with **Meta's free test number** using only a developer
account. Going live with Primora's own number needs a verified Meta Business
account (part B).

Meta renames its screens from time to time. If a label below doesn't match,
look for the nearest equivalent.

---

## A. Testing on staging (developer account, free test number)

### 1. Create the app
1. Go to <https://developers.facebook.com/apps> and choose **Create app**.
2. When asked what the app is for, pick the **WhatsApp** / business messaging
   option. Name it e.g. "Primora Tenants".
3. Meta asks for a *business portfolio*. Create one called "Primora". An
   unverified portfolio is fine for testing.
4. In the app, open **WhatsApp → API Setup**. You'll see:
   - a **test phone number** (the "From" number), and its **Phone number ID**;
   - a **temporary access token**, valid for about 24 hours;
   - **To**: add up to 5 phone numbers that may message the test number. Each
     one gets a confirmation code on WhatsApp. Add your own phone, and any
     tenant phones you test with.

### 2. Long-lived token (so it doesn't expire every day)
1. Go to <https://business.facebook.com/settings>, then **Users → System users**.
   Add a system user ("primora-bot", role *Admin*).
2. Use **Assign assets** to give it the app (full control) and the WhatsApp
   account.
3. Use **Generate new token**: choose the app, set expiry to **Never**, and tick
   `whatsapp_business_messaging` and `whatsapp_business_management`.
4. Copy the token. It is shown only once.

You can start with the temporary token from step 1 and swap it later.

### 3. App secret
In the app, open **App settings → Basic → App secret** and click **Show**. This
lets Primora check that webhook calls really come from Meta.

### 4. Put the settings on the server
```bash
sudo primora env staging
```
Add or fill in:
```
WHATSAPP_TOKEN=<token from step 2 (or the temporary one)>
WHATSAPP_PHONE_NUMBER_ID=<Phone number ID from API Setup>
WHATSAPP_APP_SECRET=<app secret from step 3>
WHATSAPP_VERIFY_TOKEN=<any long random string you make up>
WHATSAPP_DISPLAY_NUMBER=<the number tenants message, e.g. +1 555 123 4567>
```
Make up the verify token with: `openssl rand -hex 24`.
Saving and closing the editor reloads staging and restarts the queue worker.

### 5. Connect the webhook
1. In the app, open **WhatsApp → Configuration → Webhook → Edit** and enter:
   - **Callback URL:** `https://staging.primoraproptech.com/api/whatsapp/webhook`
   - **Verify token:** the same `WHATSAPP_VERIFY_TOKEN`
2. Click **Verify and save**. Meta calls the URL once; Primora answers if the
   token matches.
3. Under **Webhook fields**, **subscribe to `messages`**.

### 6. Try it
From one of the "To" phones, send **Hi** to the test number.
- **If that phone is a tenant's phone in staging:** you get the menu.
- **If not:** you're asked for a unit ID, and a code goes to the phone or email
  on that tenant's record.

Use test data only; never use real tenants' numbers.

**If nothing comes back**, check, on the server:
```bash
sudo primora logs staging                            # application errors
sudo primora artisan staging tinker --execute="echo App\Models\WhatsAppMessage::latest('id')->take(5)->get(['direction','type','status','body','error'])->toJson();"
```
- **No incoming messages at all:** the webhook isn't connected or subscribed to
  `messages`, or the app secret is wrong. Primora refuses unsigned calls with
  403.
- **Outgoing messages with status `failed`:** the token is invalid or expired,
  or the phone isn't in the "To" list.
- **Status `logged`:** `WHATSAPP_TOKEN` or `WHATSAPP_PHONE_NUMBER_ID` is
  missing.

---

## B. Going live with Primora's own number

1. **Verify the business:** in Business settings → **Security Centre /
   Business verification**, submit Primora's company documents. Meta reviews
   this, usually within days.
2. **Add the real number** in WhatsApp Manager → **Phone numbers → Add**:
   - it must **not** be on the WhatsApp or WhatsApp Business app already
     (delete it there first);
   - it receives a verification code by SMS or voice call;
   - set the **display name** ("Primora"), which Meta approves.
3. **Payment method:** add one in WhatsApp Manager. It's needed even though
   replies to tenants are currently free (see below).
4. **Update production's settings:**
   - `WHATSAPP_PHONE_NUMBER_ID`: the new number's ID;
   - `WHATSAPP_DISPLAY_NUMBER`: the new number;
   - the same token, if the system user has the WhatsApp account assigned.
5. **Webhook for production:** point it at
   `https://app.primoraproptech.com/api/whatsapp/webhook`. The app has one
   webhook URL, so use a separate Meta app for staging if you want both
   running.

### Costs (check Meta's current pricing)
- **Free (phase 1):** replies within 24 hours of a tenant's message (the
  "customer service window"). The whole menu works this way.
- **Charged per message (phase 2):** messages Primora starts, such as
  new-invoice alerts, receipts and reminders. These need **message templates**
  approved by Meta.
