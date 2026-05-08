async function main() {
  const { default: app } = await import('./app.js');
  const { default: logger } = await import('./utils/logger.js');
  const { closeDB } = await import('./config/database.js');

  const args = process.argv.slice(2);
  let PORT = process.env.PORT || 8080;
  const portIndex = args.indexOf('-p');
  if (portIndex !== -1 && args[portIndex + 1]) {
    PORT = parseInt(args[portIndex + 1], 10) || 8080;
  }

  const server = app.listen(PORT, () => {
    logger.info(`Writer Blog running at http://localhost:${PORT}`);
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

main().catch(err => {
  console.error('Failed to start app:', err);
  process.exit(1);
});
