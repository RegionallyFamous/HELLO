export class WordPressClient {
  constructor({ baseUrl, botSecret, fetchImpl = fetch }) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.botSecret = botSecret;
    this.fetch = fetchImpl;
  }

  async postIncomingMessage(payload) {
    const response = await this.fetch(`${this.baseUrl}/wp-json/beeper-comments/v1/incoming`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        ...payload,
        bot_secret: this.botSecret
      })
    });

    const body = await response.json().catch(() => ({}));

    if (response.status === 404) {
      return { ignored: true, status: response.status, body };
    }

    if (!response.ok) {
      throw new Error(`WordPress webhook failed with ${response.status}: ${JSON.stringify(body)}`);
    }

    return body;
  }
}
