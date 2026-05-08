module.exports = {
  apps: [
    {
      name: 'writer-blog',
      script: './pm2-entry.cjs',
      cwd: '/home/admin/apps/writer-blog/releases/current',
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      env: {
        NODE_ENV: 'production'
      }
    }
  ]
};
