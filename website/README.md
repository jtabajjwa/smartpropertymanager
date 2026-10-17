# Primora website

Marketing site for Primora. It's plain PHP, CSS and JS with no build step and no database.

```
index.php          the page
contact.php        contact-form handler (PHP mail)
config.php         ← all settings: app URL, contact details, pricing tiers
assets/            css, js, fonts (Inter, self-hosted), logo/favicon
.htaccess          security headers, caching, HTTPS redirect (commented)
```

Local preview (WAMP): http://localhost/smartpropertymanager/website/

This folder lives inside the Primora app repository but is deployed separately. Only its contents go to the website's `public_html`. The Laravel app ignores it and never serves it.

## Before uploading: edit `config.php`

| Setting | Set it to |
|---|---|
| `app_url` | Live URL of the Primora app, e.g. `https://app.yourdomain.com` (the Sign in and Start free trial buttons use it) |
| `site_url` | This website's address, e.g. `https://www.yourdomain.com` |
| `contact_email`, `contact_phone`, `whatsapp`, `address` | Your public contact details. Any left empty are hidden. |
| `mail_to` | Inbox that should receive contact-form enquiries |
| `mail_from` | An address **on your Namecheap domain**, e.g. `no-reply@yourdomain.com`. Create it in cPanel → Email Accounts. |
| `tiers` | Keep in line with the plans in the staff portal |

## Deploy to Namecheap (cPanel)

1. Log in to cPanel and open **File Manager** → `public_html`.
2. Upload the contents of this folder (not the folder itself) into `public_html`. Zipping it, uploading the zip and using **Extract** is quickest. Make sure the hidden `.htaccess` file is included.
3. In cPanel → **Select PHP Version** (or **MultiPHP Manager**), choose PHP 8.1 or newer.
4. In cPanel → **SSL/TLS Status**, run AutoSSL for the domain. Once the padlock works, uncomment the "Force HTTPS" block in `.htaccess`.
5. Visit the site and send yourself a test message through the contact form.

## Notes

- If the Primora app will live on the same hosting under a subdomain (e.g. `app.yourdomain.com`), it needs its own document root pointing at the app's `public/` folder. Keep it separate from this site's `public_html`.
- When you change CSS or JS, bump the `?v=1` in `index.php` so visitors' browsers fetch the new file.
