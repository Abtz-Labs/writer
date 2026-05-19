# Deployment

This project uses [bare](https://github.com/yourorg/bare) for deployment to a remote server.

## Configuration

Deployment is configured in `bare.config.json`:

- **Target server**: `raspberrypi.local` (admin user, SSH port 22)
- **Deploy path**: `/home/admin/apps/writer-blog`
- **Keep releases**: 5

## Deployment Process

Run `npm run deploy` or `bare deploy` to trigger deployment:

### Pre-deployment scripts

1. `git add .`
2. `git commit -m 'Bump version'`
3. `git push`

### Post-deployment scripts

1. Install production dependencies: `npm install --production`
2. Generate `.env` from `.env.example` with environment-specific values
3. Copy shared `.env` to current release

### Start script

```bash
pm2 restart ~/apps/writer-blog/releases/current/ecosystem.config.cjs --env production --update-env
```

## Ignored Files

The following files are excluded from deployment:

- `.git/*`, `node_modules/*`, `coverage/*`, `__mocks__/*`, `test/*`
- `.env`, `Dockerfile`, `docker-compose.yml`, `.dockerignore`
- `jest.config.js`, `bare.config.json`, `*.log`, `logs/*`, `storage/*`

## PM2 Configuration

Production uses PM2 with `ecosystem.config.cjs`:

- **App name**: `writer-blog`
- **Entry point**: `./pm2-entry.cjs`
- **Auto-restart**: Enabled (max 10 restarts, min uptime 10s)
- **Environment**: `NODE_ENV=production`
