function timestamp() {
  return new Date().toISOString();
}

function log(level, message, ...args) {
  const extra = args.length
    ? ' ' + args.map(a => (typeof a === 'object' ? JSON.stringify(a) : String(a))).join(' ')
    : '';
  // eslint-disable-next-line no-console
  console.log(`[${timestamp()}] [${level}] ${message}${extra}`);
}

module.exports = {
  info: (msg, ...args) => log('INFO', msg, ...args),
  warn: (msg, ...args) => log('WARN', msg, ...args),
  error: (msg, ...args) => log('ERROR', msg, ...args),
};
