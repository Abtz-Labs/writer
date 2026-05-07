const crypto = require('crypto');

class Settings {
  constructor(data = {}) {
    this.id = 'settings';
    this.title = data.title || '';
    this.author = data.author || '';
    this.description = data.description || '';
    this.username = data.username || '';
    this.password_hash = data.password_hash || '';
    this.auth_token = data.auth_token || '';
    this.about = data.about || '';
    this.custom_scripts = data.custom_scripts || '';
    this.onboarding_complete = data.onboarding_complete || false;
    this.show_docs = data.show_docs !== undefined ? data.show_docs : true;
    this.created_at = data.created_at || new Date().toISOString();
    this.updated_at = data.updated_at || new Date().toISOString();
  }

  static generateToken() {
    return crypto.randomBytes(32).toString('hex');
  }

  static hashPassword(password) {
    const salt = crypto.randomBytes(16).toString('hex');
    const hash = crypto.scryptSync(password, salt, 64).toString('hex');
    return `$scrypt$${salt}$${hash}`;
  }

  verifyPassword(password) {
    if (!this.password_hash) return false;

    // Legacy SHA256 hashes are exactly 64 hex characters
    const isOldHash = /^[a-f0-9]{64}$/i.test(this.password_hash);

    let expectedHash;
    if (isOldHash) {
      expectedHash = crypto.createHash('sha256').update(password).digest('hex');
    } else if (this.password_hash.startsWith('$scrypt$')) {
      const parts = this.password_hash.split('$');
      if (parts.length !== 4) return false;
      const salt = parts[2];
      expectedHash = crypto.scryptSync(password, salt, 64).toString('hex');
    } else {
      return false;
    }

    const storedHash = isOldHash ? this.password_hash : this.password_hash.split('$')[3];

    try {
      const expectedBuf = Buffer.from(expectedHash, 'hex');
      const storedBuf = Buffer.from(storedHash, 'hex');
      if (expectedBuf.length !== storedBuf.length) return false;
      return crypto.timingSafeEqual(expectedBuf, storedBuf);
    } catch {
      return false;
    }
  }

  static fromDB(obj) {
    return new Settings(obj);
  }

  toJSON() {
    return {
      id: this.id,
      title: this.title,
      author: this.author,
      description: this.description,
      username: this.username,
      hasPassword: !!this.password_hash,
      auth_token: this.auth_token,
      about: this.about,
      custom_scripts: this.custom_scripts,
      onboarding_complete: this.onboarding_complete,
      show_docs: this.show_docs,
      created_at: this.created_at,
      updated_at: this.updated_at
    };
  }
}

module.exports = Settings;
