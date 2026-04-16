const express = require('express');
const router = express.Router();
const { getCollection } = require('../config/database');
const Post = require('../models/post');
const Settings = require('../models/settings');

async function getSettings() {
  const settingsCollection = getCollection('settings');
  const settingsData = await settingsCollection.find({ id: 'settings' });
  const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
  return settingsObj ? Settings.fromDB(settingsObj).toJSON() : { title: 'MiniMedium Blog' };
}

router.get('/', async (req, res, next) => {
  try {
    const { tag } = req.query;
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find();
    const allPosts = (postsData || []).map(p => Post.fromDB(p).toView());
    
    const allTags = new Set();
    allPosts.forEach(post => {
      if (post.tags && Array.isArray(post.tags)) {
        post.tags.forEach(t => allTags.add(t));
      }
    });
    const tags = Array.from(allTags).sort();
    
    let posts = allPosts
      .filter(p => p.status === 'published')
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
      .slice(0, 10);
    
    if (tag) {
      posts = posts.filter(p => p.tags && p.tags.includes(tag));
    }
    
    const settings = await getSettings();
    res.render('index', { posts, settings, tags, activeTag: tag || null });
  } catch (err) {
    next(err);
  }
});

router.get('/post/:slug', async (req, res, next) => {
  try {
    const postsCollection = getCollection('posts');
    const allPosts = await postsCollection.find() || [];
    const post = allPosts.find(p => p.slug === req.params.slug && p.status === 'published');
    
    if (!post) {
      return res.status(404).send('Post not found');
    }
    
    const sortedPosts = allPosts
      .map(p => Post.fromDB(p).toView())
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    
    const currentIndex = sortedPosts.findIndex(p => p.slug === post.slug);
    const prevPost = currentIndex > 0 ? sortedPosts[currentIndex - 1] : null;
    const nextPost = currentIndex < sortedPosts.length - 1 ? sortedPosts[currentIndex + 1] : null;
    
    const settings = await getSettings();
    const postView = Post.fromDB(post).toView();
    res.render('post', { 
      post: postView,
      prevPost,
      nextPost,
      settings
    });
  } catch (err) {
    next(err);
  }
});

router.get('/docs', async (req, res, next) => {
  try {
    const settings = await getSettings();
    res.render('docs', { settings });
  } catch (err) {
    next(err);
  }
});

router.get('/onboarding', async (req, res, next) => {
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (settingsObj?.onboarding_complete) {
      return res.redirect('/');
    }
    
    res.render('onboarding', { settings: { title: 'Setup Your Blog' }, error: null });
  } catch (err) {
    next(err);
  }
});

router.get('/settings', async (req, res, next) => {
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj?.onboarding_complete) {
      return res.redirect('/onboarding');
    }
    
    const isAuthenticated = req.session.authToken === settingsObj.auth_token;
    const settings = Settings.fromDB(settingsObj).toJSON();
    res.render('settings', { settings, isAuthenticated, error: null, token: req.session.authToken || '' });
  } catch (err) {
    next(err);
  }
});

router.post('/settings/login', async (req, res, next) => {
  try {
    const { token, username, password } = req.body;
    
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj) {
      return res.redirect('/onboarding');
    }
    
    const settings = Settings.fromDB(settingsObj);
    let isValid = false;
    
    if (token) {
      isValid = token === settingsObj.auth_token;
    } else if (username && password) {
      isValid = settings.username === username && settings.verifyPassword(password);
    }
    
    if (isValid) {
      req.session.authToken = settingsObj.auth_token;
      return res.redirect('/settings');
    }
    
    res.render('settings', { 
      settings: settings.toJSON(), 
      isAuthenticated: false, 
      error: 'Invalid credentials',
      token: ''
    });
  } catch (err) {
    next(err);
  }
});

router.post('/settings/logout', (req, res, next) => {
  req.session = null;
  res.redirect('/settings');
});

router.post('/settings/update-credentials', async (req, res, next) => {
  try {
    const { username, password, confirmPassword } = req.body;
    
    if (password && password !== confirmPassword) {
      return res.render('settings', {
        settings: Settings.fromDB((await getCollection('settings').find({ id: 'settings' }))[0]).toJSON(),
        isAuthenticated: true,
        error: 'Passwords do not match',
        token: req.session.authToken || ''
      });
    }
    
    const response = await fetch(`${req.protocol}://${req.get('host')}/api/settings/credentials`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': req.session.authToken
      },
      body: JSON.stringify({ username, password })
    });
    
    const data = await response.json();
    
    if (data.error) {
      return res.render('settings', {
        settings: Settings.fromDB((await getCollection('settings').find({ id: 'settings' }))[0]).toJSON(),
        isAuthenticated: true,
        error: data.message,
        token: req.session.authToken || ''
      });
    }
    
    res.redirect('/settings');
  } catch (err) {
    next(err);
  }
});

router.get('/panel', async (req, res, next) => {
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj?.onboarding_complete) {
      return res.redirect('/onboarding');
    }
    
    const settings = Settings.fromDB(settingsObj).toJSON();
    const isAuthenticated = req.session.authToken === settingsObj.auth_token;
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find() || [];
    const posts = postsData.map(p => {
      const post = Post.fromDB(p).toJSON();
      delete post.bodyHtml;
      return post;
    });
    
    res.render('panel', { posts, isAuthenticated, error: null, token: req.session.authToken || '', settings });
  } catch (err) {
    next(err);
  }
});

router.post('/panel/login', async (req, res, next) => {
  try {
    const { token, username, password } = req.body;
    
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj) {
      return res.redirect('/onboarding');
    }
    
    const settings = Settings.fromDB(settingsObj);
    let isValid = false;
    
    if (token) {
      isValid = token === settingsObj.auth_token;
    } else if (username && password) {
      isValid = settings.username === username && settings.verifyPassword(password);
    }
    
    if (isValid) {
      req.session.authToken = settingsObj.auth_token;
      return res.redirect('/panel');
    }
    
    res.render('panel', { 
      posts: [], 
      isAuthenticated: false, 
      error: 'Invalid credentials',
      token: '',
      settings: settings.toJSON()
    });
  } catch (err) {
    next(err);
  }
});

router.post('/panel/logout', (req, res, next) => {
  req.session = null;
  res.redirect('/panel');
});

router.get('/posts', async (req, res, next) => {
  try {
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj?.onboarding_complete) {
      return res.redirect('/onboarding');
    }
    
    const isAuthenticated = req.session.authToken === settingsObj.auth_token;
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find() || [];
    const posts = postsData.map(p => {
      const post = Post.fromDB(p).toJSON();
      delete post.bodyHtml;
      delete post.body;
      return post;
    });
    
    res.render('posts', { posts, isAuthenticated, error: null, token: req.session.authToken || '', editSlug: '' });
  } catch (err) {
    next(err);
  }
});

router.post('/posts/login', async (req, res, next) => {
  try {
    const { token } = req.body;
    
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj) {
      return res.redirect('/onboarding');
    }
    
    if (token === settingsObj.auth_token) {
      req.session.authToken = token;
      return res.redirect('/posts');
    }
    
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find() || [];
    const posts = postsData.map(p => {
      const post = Post.fromDB(p).toJSON();
      delete post.bodyHtml;
      delete post.body;
      return post;
    });
    
    res.render('posts', { posts, isAuthenticated: false, error: 'Invalid auth token', token: '' });
  } catch (err) {
    next(err);
  }
});

router.post('/posts/logout', (req, res, next) => {
  req.session = null;
  res.redirect('/posts');
});

module.exports = router;