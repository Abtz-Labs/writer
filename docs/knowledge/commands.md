# Commands

## Development

```bash
npm start              # Dev server with nodemon hot-reload (default port 8080)
node app.js            # Production start
node app.js -p 4000    # Custom port
```

## Testing

```bash
npm test               # Jest tests with coverage
npm run test:watch     # Jest watch mode
npx jest test/metadata.test.js              # Single test file
npx jest --testNamePattern="slug generation" # Specific test by name
```

## Deployment

```bash
npm run deploy         # Deploy using bare deploy
bare deploy            # Direct bare deploy command
```

## Docker

```bash
docker-compose up      # Run in Docker
```
