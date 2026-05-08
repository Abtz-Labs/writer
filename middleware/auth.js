import { getCollection } from '../config/database.js';
import Settings from '../models/settings.js';

async function authMiddleware(req, res, next) {
  const token = req.headers['x-auth-token'];
  
  if (!token) {
    return res.status(401).json({
      error: 'Unauthorized',
      message: 'X-Auth-Token header is required',
      hint: 'Visit /onboarding to generate an auth token'
    });
  }
  
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj) {
      return res.status(401).json({
        error: 'Unauthorized',
        message: 'Blog not configured',
        hint: 'Visit /onboarding to set up your blog'
      });
    }
    
    const settings = Settings.fromDB(settingsObj);
    
    if (settings.auth_token !== token) {
      return res.status(401).json({
        error: 'Unauthorized',
        message: 'Invalid auth token'
      });
    }
    
    req.settings = settings;
    next();
  } catch (err) {
    return res.status(500).json({
      error: 'Internal Server Error',
      message: 'Authentication failed'
    });
  }
}

async function optionalAuth(req, res, next) {
  const token = req.headers['x-auth-token'];
  if (!token) return next();

  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;

    if (settingsObj && settingsObj.auth_token === token) {
      req.settings = Settings.fromDB(settingsObj);
    }
    next();
  } catch (err) {
    next();
  }
}

export default authMiddleware;
export { optionalAuth };