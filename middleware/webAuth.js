import { getCollection } from '../config/database.js';

async function requireWebAuth(req, res, next) {
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj?.onboarding_complete) {
      return res.redirect('/onboarding');
    }
    
    const isAuthenticated = req.session.authToken === settingsObj.auth_token;
    
    if (!isAuthenticated) {
      const redirectTo = encodeURIComponent(req.originalUrl);
      return res.redirect(`/login?redirect=${redirectTo}`);
    }
    
    req.settings = settingsObj;
    next();
  } catch (err) {
    next(err);
  }
}

export default requireWebAuth;