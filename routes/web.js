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
  return settingsObj ? Settings.fromDB(settingsObj).toJSON() : { title: 'Serif Blog' };
}

function fuzzyScore(query, text) {
  if (!query || !text) return 0;
  const q = query.toLowerCase().trim();
  const t = text.toLowerCase().trim();
  if (q === t) return 1000;
  if (t.includes(q)) return 500 + q.length;

  let score = 0;
  let tIdx = 0;
  let consecutive = 0;
  for (let i = 0; i < q.length; i++) {
    const ch = q[i];
    let found = false;
    while (tIdx < t.length) {
      if (t[tIdx] === ch) {
        found = true;
        score += 1 + consecutive;
        consecutive++;
        tIdx++;
        break;
      }
      consecutive = 0;
      tIdx++;
    }
    if (!found) return 0;
  }
  return score;
}

function searchPosts(posts, query) {
  if (!query || !query.trim()) return [];
  const q = query.trim();
  const results = posts.map(post => {
    const titleScore = fuzzyScore(q, post.title) * 10;
    const excerptScore = fuzzyScore(q, post.excerpt) * 4;
    const bodyScore = fuzzyScore(q, post.body) * 1;
    const slugScore = fuzzyScore(q, post.slug) * 6;
    const tagsScore = post.tags && Array.isArray(post.tags) ? fuzzyScore(q, post.tags.join(' ')) * 8 : 0;
    const keywordsScore = post.keywords && Array.isArray(post.keywords) ? fuzzyScore(q, post.keywords.join(' ')) * 7 : 0;
    const totalScore = titleScore + excerptScore + bodyScore + slugScore + tagsScore + keywordsScore;
    return { post, score: totalScore };
  }).filter(r => r.score > 0).sort((a, b) => b.score - a.score).map(r => r.post);
  return results;
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

router.get('/search', async (req, res, next) => {
  try {
    const { q, page } = req.query;
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

    const results = searchPosts(publishedPosts, q);

    const totalPosts = results.length;
    const totalPages = Math.ceil(totalPosts / limit);
    const posts = results.slice(skip, skip + limit);

    const settings = await getSettings();
    res.render('search', {
      posts,
      settings,
      tags,
      query: q || '',
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
      title: settingsObj.title || 'Serif Blog',
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
      title: settingsObj.title || 'Serif Blog',
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
    const { page, q } = req.query;
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

    let filteredPosts = allPosts;
    if (q && q.trim()) {
      filteredPosts = searchPosts(allPosts, q);
    }

    const totalPosts = filteredPosts.length;
    const totalPages = Math.ceil(totalPosts / limit);
    const posts = filteredPosts.slice(skip, skip + limit);

    res.render('panel', {
      posts,
      error: null,
      settings,
      token: req.session.authToken,
      isAuthenticated: true,
      currentPage: pageNum,
      totalPages,
      prevPage: pageNum > 1 ? pageNum - 1 : null,
      nextPage: pageNum < totalPages ? pageNum + 1 : null,
      query: q || ''
    });
  } catch (err) {
    next(err);
  }
});

router.get('/posts', (req, res) => {
  res.redirect('/panel');
});

module.exports = router;