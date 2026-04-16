const JSLiteDB = require('jslitedb');
const path = require('path');
const fs = require('fs');

const dbPath = path.resolve(__dirname, '..', 'storage', 'data');

let db;

function getDB() {
  if (!db) {
    db = new JSLiteDB({ folderPath: dbPath, lazyLoading: false });
  }
  return db;
}

function getCollection(name) {
  const database = getDB();
  return database.collection(name);
}

function closeDB() {
  if (db) {
    db.close();
    db = null;
  }
}

function saveDB() {
  if (db) {
    db.save();
  }
}

function forceSavePost(post) {
  const postsFile = path.join(dbPath, 'posts.json');
  let data = {};
  if (fs.existsSync(postsFile)) {
    data = JSON.parse(fs.readFileSync(postsFile, 'utf8'));
  }
  data[post.id] = post;
  fs.writeFileSync(postsFile, JSON.stringify(data, null, 2));
}

module.exports = {
  getDB,
  getCollection,
  closeDB,
  saveDB,
  forceSavePost
};
