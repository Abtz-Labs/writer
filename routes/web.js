const express = require('express');
const router = express.Router();
const { getCollection } = require('../config/database');
const Post = require('../models/post');
const Settings = require('../models/settings');
const requireWebAuth = require('../middleware/webAuth');

async function getSettings() {
  const settingsCollection = getCollection('settings');
  const settingsData = await settingsCollection.find({ id: 'settings' });
  const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
  return settingsObj ? Settings.fromDB(settingsObj).toJSON() : { title: 'MiniMedium Blog' };
}

router.get('/', async (req, res, next) => {
  try {
    const { tag, page } = req.query;
    const pageNum = Math.max(1, parseInt(page) || 1);
    const limit = 10;
    const skip = (pageNum - 1) * limit;
    
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find();
    const allPosts = (postsData || []).map(p => Post.fromDB(p).toView());
    
    const publishedPosts = allPosts.filter(p => p.status === 'published');
    
    const allTags = new Set();
    publishedPosts.forEach(post => {
      if (post.tags && Array.isArray(post.tags)) {
        post.tags.forEach(t => allTags.add(t));
      }
    });
    const tags = Array.from(allTags).sort();
    
    let filteredPosts = publishedPosts
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    
    if (tag) {
      filteredPosts = filteredPosts.filter(p => p.tags && p.tags.includes(tag));
    }
    
    const totalPosts = filteredPosts.length;
    const totalPages = Math.ceil(totalPosts / limit);
    const posts = filteredPosts.slice(skip, skip + limit);
    
    const settings = await getSettings();
    res.render('index', { 
      posts, 
      settings, 
      tags, 
      activeTag: tag || null,
      currentPage: pageNum,
      totalPages,
      prevPage: pageNum > 1 ? pageNum - 1 : null,
      nextPage: pageNum < totalPages ? pageNum + 1 : null
    });
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
      const settings = await getSettings();
      return res.status(404).render('404', { 
        message: 'This post may be a draft or has been removed.',
        settings
      });
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

router.get('/login', async (req, res, next) => {
  try {
    const redirectTo = req.query.redirect || '/';
    const settingsCollection = getCollection('settings');
    const settingsData = await settingsCollection.find({ id: 'settings' });
    const settingsObj = settingsData && settingsData.length > 0 ? settingsData[0] : null;
    
    if (!settingsObj?.onboarding_complete) {
      return res.redirect('/onboarding');
    }
    
    if (req.session.authToken === settingsObj.auth_token) {
      return res.redirect(redirectTo);
    }
    
    res.render('login', { 
      title: settingsObj.title || 'MiniMedium Blog',
      subtitle: 'Enter your credentials to continue',
      action: '/login',
      showUsername: !!settingsObj.username,
      tokenRequired: !settingsObj.username,
      error: null,
      redirectTo,
      settings: { title: settingsObj.title, author: settingsObj.author }
    });
  } catch (err) {
    next(err);
  }
});

router.post('/login', async (req, res, next) => {
  try {
    const { token, username, password } = req.body;
    const redirectTo = req.body.redirect || '/';
    
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
      return res.redirect(redirectTo);
    }
    
    res.render('login', {
      title: settingsObj.title || 'MiniMedium Blog',
      subtitle: 'Enter your credentials to continue',
      action: '/login',
      showUsername: !!settingsObj.username,
      tokenRequired: !settingsObj.username,
      error: 'Invalid credentials',
      redirectTo,
      settings: { title: settingsObj.title, author: settingsObj.author }
    });
  } catch (err) {
    next(err);
  }
});

router.post('/logout', (req, res, next) => {
  req.session = null;
  res.redirect('/');
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

router.get('/settings', requireWebAuth, async (req, res, next) => {
  try {
    const settings = Settings.fromDB(req.settings).toJSON();
    res.render('settings', { settings, error: null, token: req.session.authToken, isAuthenticated: true });
  } catch (err) {
    next(err);
  }
});

router.post('/settings/update', requireWebAuth, async (req, res, next) => {
  try {
    const response = await fetch(`${req.protocol}://${req.get('host')}/api/settings`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': req.session.authToken
      },
      body: JSON.stringify(req.body)
    });
    
    res.redirect('/settings');
  } catch (err) {
    next(err);
  }
});

router.post('/settings/update-credentials', requireWebAuth, async (req, res, next) => {
  try {
    const { username, password, confirmPassword } = req.body;
    
    if (password && password !== confirmPassword) {
      return res.render('settings', {
        settings: Settings.fromDB((await getCollection('settings').find({ id: 'settings' }))[0]).toJSON(),
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
        error: data.message,
        token: req.session.authToken || ''
      });
    }
    
    res.redirect('/settings');
  } catch (err) {
    next(err);
  }
});

router.get('/panel', requireWebAuth, async (req, res, next) => {
  try {
    const { page } = req.query;
    const pageNum = Math.max(1, parseInt(page) || 1);
    const limit = 15;
    const skip = (pageNum - 1) * limit;
    
    const settings = Settings.fromDB(req.settings).toJSON();
    const postsCollection = getCollection('posts');
    const postsData = await postsCollection.find() || [];
    const allPosts = postsData.map(p => {
      const post = Post.fromDB(p).toJSON();
      delete post.bodyHtml;
      return post;
    });
    
    const totalPosts = allPosts.length;
    const totalPages = Math.ceil(totalPosts / limit);
    const posts = allPosts.slice(skip, skip + limit);
    
    res.render('panel', { 
      posts, 
      error: null, 
      settings, 
      token: req.session.authToken, 
      isAuthenticated: true,
      currentPage: pageNum,
      totalPages,
      prevPage: pageNum > 1 ? pageNum - 1 : null,
      nextPage: pageNum < totalPages ? pageNum + 1 : null
    });
  } catch (err) {
    next(err);
  }
});

router.get('/posts', (req, res) => {
  res.redirect('/panel');
});

module.exports = router;