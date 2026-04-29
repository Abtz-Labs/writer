module.exports = {
  apps: [
    {
      name: 'serif-blog',
      script: './app.js',
      cwd: '/home/admin/apps/serif-blog/current',
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      env: {
        NODE_ENV: 'production'
      }
    }
  ]
};
