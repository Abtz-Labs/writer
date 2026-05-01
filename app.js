require('dotenv').config();

const express = require('express');
const path = require('path');
const ejs = require('ejs');
const cookieSession = require('cookie-session');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');

const apiRoutes = require('./routes/api');
const webRoutes = require('./routes/web');
const { errorHandler, notFoundHandler } = require('./middleware/errorHandler');
const { closeDB } = require('./config/database');
const logger = require('./utils/logger');

const app = express();

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

app.use((req, res, next) => {
  const originalRender = res.render.bind(res);
  res.render = (view, data, callback) => {
    const renderData = { ...data };
    ejs.renderFile(path.join(__dirname, 'views', 'pages', view + '.ejs'), renderData, (err, content) => {
      if (err) {
        if (callback) callback(err);
        else next(err);
        return;
      }
      renderData.body = content;
      renderData.title = renderData.title || data?.post?.title || data?.settings?.title || 'Serif Blog';
      renderData.baseUrl = `${req.protocol}://${req.get('host')}`;
      originalRender('layout', renderData, callback);
    });
  };
  next();
});

app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      styleSrc: ["'self'", "'unsafe-inline'"],
      imgSrc: ["'self'", "data:", "https:"],
      scriptSrc: ["'self'", "'unsafe-inline'"],
    },
  },
}));

const limiter = rateLimit({
  windowMs: parseInt(process.env.RATE_LIMIT_WINDOW_MS, 10) || 15 * 60 * 1000,
  max: parseInt(process.env.RATE_LIMIT_MAX, 10) || 100,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many requests', message: 'Please slow down' },
});
app.use(limiter);

app.use(cookieSession({
  name: 'session',
  keys: [process.env.SESSION_KEY || 'default-secret-key-change-in-production'],
  maxAge: 24 * 60 * 60 * 1000
}));

app.use(express.json({ limit: '1mb' }));
app.use(express.urlencoded({ extended: true, limit: '1mb' }));

app.use(express.static(path.join(__dirname, 'public')));

app.use('/', webRoutes);
app.use('/api', apiRoutes);

app.use(notFoundHandler);
app.use(errorHandler);

const args = process.argv.slice(2);
let PORT = process.env.PORT || 8080;
const portIndex = args.indexOf('-p');
if (portIndex !== -1 && args[portIndex + 1]) {
  PORT = parseInt(args[portIndex + 1], 10) || 8080;
}

let server;

if (require.main === module) {
  server = app.listen(PORT, () => {
    logger.info(`Serif Blog running at http://localhost:${PORT}`);
    logger.info(`API docs: http://localhost:${PORT}/api`);
  });

  function gracefulShutdown(signal) {
    logger.info(`${signal} received. Shutting down gracefully...`);
    server.close(() => {
      logger.info('HTTP server closed.');
      closeDB();
      logger.info('Database closed.');
      process.exit(0);
    });
  }

  process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
  process.on('SIGINT', () => gracefulShutdown('SIGINT'));
}

module.exports = app;