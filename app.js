const express = require('express');
const path = require('path');
const ejs = require('ejs');
const cookieSession = require('cookie-session');

const apiRoutes = require('./routes/api');
const webRoutes = require('./routes/web');
const { errorHandler, notFoundHandler } = require('./middleware/errorHandler');

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
      originalRender('layout', renderData, callback);
    });
  };
  next();
});

app.use(cookieSession({
  name: 'session',
  keys: [process.env.SESSION_KEY || 'default-secret-key-change-in-production'],
  maxAge: 24 * 60 * 60 * 1000
}));

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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

app.listen(PORT, () => {
  console.log(`Serif Blog running at http://localhost:${PORT}`);
  console.log(`API docs: http://localhost:${PORT}/api`);
});

module.exports = app;