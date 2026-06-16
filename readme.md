# Claude AI OpenAI-Compatible API

A simple PHP script that lets you connect Claude AI to applications and tools designed for OpenAI.
By mirroring OpenAI's API structure, this wrapper allows your favorite OpenAI-compatible tools, SDKs, and platforms to communicate with Claude with minimal or no code changes.

> [!WARNING]
> This project is completely unofficial and is not affiliated with, endorsed by, or sponsored by Anthropic or Claude AI. It relies on web account sessions, which may change or expire at any time.

## ✨ Features

* **OpenAI Drop-In Replacement:** Supports standard `/v1/chat/completions` and `/v1/models` endpoints.
* **Live Streaming:** Supports real-time text streaming so responses type out live.
* **Easy Single-File Setup:** No complex installations, dependencies, or package managers required. Just upload the single PHP file and go.
* **Broad Compatibility:** Works smoothly with the official OpenAI SDKs and third-party AI interfaces.

## 📦 Supported Models

* `claude-sonnet-4-6`
* `claude-opus-4-6`
* `claude-haiku-4-5`

## 📋 Requirements

* PHP 8.0 or later
* PHP cURL extension enabled
* A standard Claude.ai account
* A valid Claude.ai session cookie and Organization ID

## 🚀 Installation & Configuration

1. Download or clone this repository.
2. Upload `claude.php` to your web server.
3. Open `claude.php` in a text editor and fill in your credentials at the top of the file:

```php
define('ORG_ID',   'YOUR_ORG_ID_HERE');
define('COOKIE',   'YOUR_COOKIE_HERE');

define('MODEL',    'claude-sonnet-4-6');
define('BASE_URL', 'https://claude.ai');
```

## 🔑 Obtaining Claude Credentials

### 1. Get Your Organization ID

1. Log in to your account at https://claude.ai.
2. Press **F12** on your keyboard to open your browser's Developer Tools and switch to the **Console** tab.
3. Paste the following code and press **Enter**:

   ```javascript
   fetch('/api/organizations')
     .then(r => r.json())
     .then(d => console.log(d[0].uuid));
   ```

4. Copy the text code (UUID) that appears.

### 2. Get Your Session Cookie

1. In the same Developer Tools window, switch to the **Network** tab.
2. Check the box that says **Preserve log**.
3. Refresh the Claude.ai webpage.
4. Search for the word `organizations` in the network search bar.
5. Click on the item that appears and look for the **Request Headers** section.
6. Find the Cookie row and copy its entire text value.

> [!IMPORTANT]
> * **Keep it Safe:** Treat your session cookie like a password. Never share it or upload it to public spaces like public GitHub repositories.
> * **Cookie Expiration:** Web cookies expire naturally over time. If the script stops working or returns an error, simply repeat these steps to get a fresh cookie.

## 📚 API Endpoints

### List Available Models

* **Endpoint:** `GET /v1/models`

**Example Request:**

```bash
curl https://your-domain.com/claude.php/v1/models
```

### Create Chat Completion

* **Endpoint:** `POST /v1/chat/completions`

**Request Body:**

```json
{
  "model": "claude-sonnet-4-6",
  "messages": [
    {
      "role": "user",
      "content": "Hello!"
    }
  ]
}
```

**Example Request:**

```bash
curl https://your-domain.com/claude.php/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet-4-6",
    "messages": [
      {
        "role": "user",
        "content": "Hello!"
      }
    ]
  }'
```

### Streaming Responses

To turn on live text streaming, just add `"stream": true` to your request data.

## 🐍 Python SDK Example

You can point the official OpenAI Python SDK to your new server by changing the `base_url`:

```python
from openai import OpenAI

client = OpenAI(
    api_key="not-needed",  # You can type anything here as a placeholder
    base_url="https://your-domain.com/claude.php/v1"
)

response = client.chat.completions.create(
    model="claude-sonnet-4-6",
    messages=[
        {
            "role": "user",
            "content": "Hello!"
        }
    ]
)

print(response.choices[0].message.content)
```

## 📝 Important Notes

* **Web Dependency:** Since this script connects through a regular web login session rather than an official developer API key, any major changes to the Claude.ai website might cause the script to stop working until it is updated.
* **No Guarantees:** This project is provided as-is without any uptime or reliability guarantees.

## ⚠️ Disclaimer

This project is an independent, community-made tool created for **educational and research purposes only**.

Users are fully responsible for making sure their use of this script complies with all local laws and the terms of service of third-party platforms. The author is not responsible for any account restrictions or issues caused by using this software.

## 📄 License

This project is open-source and free to use under the MIT License.

## 📢 Community & Support

### Join the Telegram Channel

To get notified about updates, fixes, and new features for this script, we invite you to join our official updates channel!

👉 **[Join the Blindroid Official Telegram Channel](https://t.me/blindroidofficial)**

### Author & Feedback

Created by **[Ismail Memon](https://t.me/Ismail_memon)**

If this script helps you out, please consider leaving a **Star ⭐** on this GitHub repository! Your support keeps the project active and helps others find it.
