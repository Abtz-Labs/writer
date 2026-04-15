const JSLiteDB = require('jslitedb');
const path = require('path');

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

module.exports = {
  getDB,
  getCollection,
  closeDB
};
