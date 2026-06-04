module.exports = {
  apps: [
    {
      name: process.env.PM2_APP_NAME || 'writer-blog',
      script: './pm2-entry.cjs',
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      env: {
        NODE_ENV: 'production'
      }
    }
  ]
};
