export class WordPressClient {
  constructor({ baseUrl = '', incomingUrl = '', botSecret, fetchImpl = fetch }) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.incomingUrl = incomingUrl;
    this.botSecret = botSecret;
    this.fetch = fetchImpl;
  }

  async postIncomingMessage(payload) {
    return this.postBotEndpoint('incoming', payload, { ignoreNotFound: true });
  }

  async getRooms() {
    const body = await this.postBotEndpoint('rooms');
    return Array.isArray(body.rooms) ? body.rooms : [];
  }

  async getHealth() {
    return this.postBotEndpoint('health');
  }

  async postBotEndpoint(endpoint, payload = {}, options = {}) {
    const url = endpoint === 'incoming' && this.incomingUrl
      ? this.incomingUrl
      : `${this.baseUrl}/wp-json/hello/v1/${endpoint}`;
    const response = await this.fetch(url, {
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

    if (options.ignoreNotFound && response.status === 404) {
      return { ignored: true, status: response.status, body };
    }

    if (!response.ok) {
      throw new Error(`WordPress webhook failed with ${response.status}: ${JSON.stringify(body)}`);
    }

    return body;
  }
}
