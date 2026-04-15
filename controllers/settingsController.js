const { getCollection } = require('../config/database');
const Settings = require('../models/settings');

class SettingsController {
  async get(req, res, next) {
    try {
      const settingsCollection = getCollection('settings');
      const settingsData = await settingsCollection.find({ id: 'settings' });
      const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
      
      if (!settingsObj) {
        return res.json({
          onboarding_complete: false,
          message: 'Blog not configured. Visit /onboarding to set up.'
        });
      }
      
      const settings = Settings.fromDB(settingsObj);
      res.json(settings.toJSON());
    } catch (err) {
      next(err);
    }
  }
  
  async create(req, res, next) {
    try {
      const { title, author, description } = req.body;
      
      if (!title) {
        return res.status(400).json({
          error: 'Validation Error',
          message: 'Blog title is required'
        });
      }
      
      const settingsCollection = getCollection('settings');
      const existingData = await settingsCollection.find({ id: 'settings' });
      const existingSettings = existingData && existingData.length > 0 ? existingData[0] : null;
      
      if (existingSettings && existingSettings.onboarding_complete) {
        return res.status(400).json({
          error: 'Conflict',
          message: 'Blog is already configured. Use PUT to update.'
        });
      }
      
      const authToken = Settings.generateToken(title);
      
      const settingsData = {
        id: 'settings',
        title,
        author: author || '',
        description: description || '',
        auth_token: authToken,
        onboarding_complete: true,
        created_at: existingSettings?.created_at || new Date().toISOString(),
        updated_at: new Date().toISOString()
      };
      
      if (existingSettings) {
        await settingsCollection.update(settingsData);
      } else {
        try {
          await settingsCollection.insert(settingsData);
        } catch (insertErr) {
          await settingsCollection.update(settingsData);
        }
      }
      
      const settings = Settings.fromDB(settingsData);
      
      res.status(201).json({
        ...settings.toJSON(),
        message: 'Blog configured successfully',
        instructions: {
          header: 'X-Auth-Token',
          value: authToken,
          usage: 'Include this token in the X-Auth-Token header for all protected API requests'
        }
      });
    } catch (err) {
      next(err);
    }
  }
  
  async update(req, res, next) {
    try {
      const settingsCollection = getCollection('settings');
      const existingData = await settingsCollection.find({ id: 'settings' });
      const existingSettings = existingData && existingData.length > 0 ? existingData[0] : null;
      
      if (!existingSettings) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Blog not configured. Use POST /api/onboarding first.'
        });
      }
      
      const { title, author, description } = req.body;
      
      const updatedSettings = {
        ...existingSettings,
        title: title !== undefined ? title : existingSettings.title,
        author: author !== undefined ? author : existingSettings.author,
        description: description !== undefined ? description : existingSettings.description,
        updated_at: new Date().toISOString()
      };
      
      await settingsCollection.update(updatedSettings);
      
      const settings = Settings.fromDB(updatedSettings);
      res.json(settings.toJSON());
    } catch (err) {
      next(err);
    }
  }

  async rotateToken(req, res, next) {
    try {
      const settingsCollection = getCollection('settings');
      const existingData = await settingsCollection.find({ id: 'settings' });
      const existingSettings = existingData && existingData.length > 0 ? existingData[0] : null;
      
      if (!existingSettings) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Blog not configured.'
        });
      }
      
      const newToken = Settings.generateToken(existingSettings.title + '-' + Date.now());
      
      const updatedSettings = {
        ...existingSettings,
        auth_token: newToken,
        updated_at: new Date().toISOString()
      };
      
      await settingsCollection.update(updatedSettings);
      
      res.json({
        message: 'Auth token rotated successfully',
        auth_token: newToken
      });
    } catch (err) {
      next(err);
    }
  }

  async updateCredentials(req, res, next) {
    try {
      const settingsCollection = getCollection('settings');
      const existingData = await settingsCollection.find({ id: 'settings' });
      const existingSettings = existingData && existingData.length > 0 ? existingData[0] : null;
      
      if (!existingSettings) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Blog not configured.'
        });
      }
      
      const { username, password } = req.body;
      const passwordHash = password ? Settings.hashPassword(password) : existingSettings.password_hash;
      
      const updatedSettings = {
        ...existingSettings,
        username: username || '',
        password_hash: passwordHash,
        updated_at: new Date().toISOString()
      };
      
      await settingsCollection.delete('settings');
      await settingsCollection.insert(updatedSettings);
      
      res.json({
        username: updatedSettings.username,
        hasPassword: !!updatedSettings.password_hash,
        message: 'Credentials updated successfully'
      });
    } catch (err) {
      next(err);
    }
  }
}

module.exports = new SettingsController();