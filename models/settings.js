const CryptoJS = require('crypto-js');

class Settings {
  constructor(data = {}) {
    this.id = 'settings';
    this.title = data.title || '';
    this.author = data.author || '';
    this.description = data.description || '';
    this.username = data.username || '';
    this.password_hash = data.password_hash || '';
    this.auth_token = data.auth_token || '';
    this.onboarding_complete = data.onboarding_complete || false;
    this.show_docs = data.show_docs !== undefined ? data.show_docs : true;
    this.created_at = data.created_at || new Date().toISOString();
    this.updated_at = data.updated_at || new Date().toISOString();
  }

  static generateToken(blogTitle) {
    const timestamp = Date.now();
    const raw = `${blogTitle}-${timestamp}`;
    return CryptoJS.MD5(raw).toString();
  }

  static hashPassword(password) {
    return CryptoJS.SHA256(password).toString();
  }

  verifyPassword(password) {
    return this.password_hash === Settings.hashPassword(password);
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
      onboarding_complete: this.onboarding_complete,
      show_docs: this.show_docs,
      created_at: this.created_at,
      updated_at: this.updated_at
    };
  }
}

module.exports = Settings;