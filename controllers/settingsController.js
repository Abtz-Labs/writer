import { getCollection } from '../config/database.js';
import Settings from '../models/settings.js';
import confirmationService from '../services/confirmation.js';
import * as ogImage from '../services/ogImage.js';

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
      const json = settings.toJSON();
      delete json.auth_token;
      res.json(json);
    } catch (err) {
      next(err);
    }
  }
  
  async create(req, res, next) {
    try {
      const { title, author, description, username, password } = req.body;

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
        custom_scripts: req.body.custom_scripts || '',
        username: username || '',
        password_hash: password ? Settings.hashPassword(password) : '',
        auth_token: authToken,
        onboarding_complete: true,
        created_at: existingSettings?.created_at || new Date().toISOString(),
        updated_at: new Date().toISOString()
      };
      
      if (existingSettings) {
        await settingsCollection.update('settings', settingsData);
      } else {
        try {
          await settingsCollection.insert(settingsData);
        } catch (insertErr) {
          await settingsCollection.update('settings', settingsData);
        }
      }

      ogImage.clearCache('site');

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
      
      const { title, author, description, about, custom_scripts, show_docs } = req.body;

      const updatedSettings = {
        ...existingSettings,
        title: title !== undefined ? title : existingSettings.title,
        author: author !== undefined ? author : existingSettings.author,
        description: description !== undefined ? description : existingSettings.description,
        about: about !== undefined ? about : existingSettings.about,
        custom_scripts: custom_scripts !== undefined ? custom_scripts : existingSettings.custom_scripts,
        show_docs: show_docs !== undefined ? show_docs : existingSettings.show_docs,
        updated_at: new Date().toISOString()
      };
      
      await settingsCollection.update('settings', updatedSettings);

      ogImage.clearCache('site');

      const settings = Settings.fromDB(updatedSettings);
      res.json(settings.toJSON());
    } catch (err) {
      next(err);
    }
  }

  async executeRotateToken() {
    const settingsCollection = getCollection('settings');
    const existingData = await settingsCollection.find({ id: 'settings' });
    const existingSettings = existingData && existingData.length > 0 ? existingData[0] : null;

    if (!existingSettings) {
      const error = new Error('Blog not configured.');
      error.status = 404;
      throw error;
    }

    const newToken = Settings.generateToken(existingSettings.title + '-' + Date.now());

    const updatedSettings = {
      ...existingSettings,
      auth_token: newToken,
      updated_at: new Date().toISOString()
    };

    await settingsCollection.update('settings', updatedSettings);

    return {
      message: 'Auth token rotated successfully',
      auth_token: newToken
    };
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

      const token = await confirmationService.create('rotate-token', {});
      const confirmationUrl = `/api/confirm/${token}`;

      res.status(202).json({
        confirmation_required: true,
        message: 'This action requires confirmation. Please send a POST request to the confirmation_url to proceed.',
        confirmation_url: confirmationUrl
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

export default new SettingsController();