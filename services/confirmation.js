const crypto = require('crypto');
const { getCollection } = require('../config/database');

class ConfirmationService {
  async create(action, data, ttlMs = 5 * 60 * 1000) {
    const token = crypto.randomBytes(16).toString('hex');
    const collection = getCollection('confirmations');

    await collection.insert({
      id: token,
      action,
      data,
      expiresAt: Date.now() + ttlMs
    });

    return token;
  }

  async get(token) {
    const collection = getCollection('confirmations');
    // JSLiteDB stores id as the document key; find() without a query
    // returns all docs with the id enriched. We filter in-memory since
    // the pending set is always small.
    const results = await collection.find();
    const item = results.find(r => r.id === token);

    if (!item) return null;

    if (Date.now() > item.expiresAt) {
      await collection.delete(token);
      return null;
    }

    return item;
  }

  async delete(token) {
    const collection = getCollection('confirmations');
    await collection.delete(token);
  }
}

module.exports = new ConfirmationService();
