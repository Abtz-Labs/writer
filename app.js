import "dotenv/config";

import express from "express";
import path from "node:path";
import { fileURLToPath } from "node:url";
import ejs from "ejs";
import cookieSession from "cookie-session";
import helmet from "helmet";
import rateLimit from "express-rate-limit";
import crypto from "node:crypto";

import apiRoutes from "./routes/api.js";
import webRoutes from "./routes/web.js";
import { errorHandler, notFoundHandler } from "./middleware/errorHandler.js";
import { getCollection, closeDB } from "./config/database.js";
import logger from "./utils/logger.js";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const app = express();

app.set("trust proxy", 1);

app.set("view engine", "ejs");
app.set("views", path.join(__dirname, "views"));

app.use((req, res, next) => {
  const originalRender = res.render.bind(res);
  res.render = (view, data, callback) => {
    const renderData = { ...data, csrfToken: req.session?.csrfToken };
    ejs.renderFile(
      path.join(__dirname, "views", "pages", view + ".ejs"),
      renderData,
      (err, content) => {
        if (err) {
          if (callback) callback(err);
          else next(err);
          return;
        }
        renderData.body = content;
        renderData.title =
          renderData.title ||
          data?.post?.title ||
          data?.settings?.title ||
          "Writer";
        renderData.baseUrl = `${req.protocol}://${req.get("host")}`;
        originalRender("layout", renderData, callback);
      },
    );
  };
  next();
});

app.use(async (req, res, next) => {
  let extraScriptDomains = [];

  try {
    const settingsCollection = getCollection("settings");
    const settingsData = await settingsCollection.find({ id: "settings" });
    const settings =
      settingsData && settingsData.length > 0 ? settingsData[0] : null;

    if (settings && settings.csp_script_domains) {
      extraScriptDomains = settings.csp_script_domains
        .split(",")
        .map((d) => d.trim())
        .filter(Boolean);
    }
  } catch {
    // If settings can't be read, proceed with default CSP
  }

  const getOrigin = (d) => {
    try {
      return new URL(d).origin;
    } catch {
      return d;
    }
  };

  const scriptSrc = ["'self'", "'unsafe-inline'", ...extraScriptDomains];
  const connectSrc = ["'self'", ...extraScriptDomains.map(getOrigin)];

  helmet({
    crossOriginEmbedderPolicy: false,
    crossOriginResourcePolicy: { policy: "cross-origin" },
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        styleSrc: ["'self'", "'unsafe-inline'"],
        imgSrc: ["'self'", "data:", "https:"],
        scriptSrc,
        connectSrc,
        scriptSrcAttr: ["'none'"],
      },
    },
  })(req, res, next);
});

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 5,
  standardHeaders: true,
  legacyHeaders: false,
  skipSuccessfulRequests: true,
  message: {
    error: "Too many requests",
    message: "Too many login attempts. Please try again later.",
  },
});
app.use("/login", loginLimiter);

const sessionKey = process.env.SESSION_KEY;
if (!sessionKey) {
  logger.warn(
    "SESSION_KEY not set. Using a random key that will change on restart.",
  );
}
app.use(
  cookieSession({
    name: "session",
    keys: [sessionKey || crypto.randomBytes(32).toString("hex")],
    maxAge: 24 * 60 * 60 * 1000,
    httpOnly: true,
    sameSite: "strict",
    secure: process.env.NODE_ENV === "production",
  }),
);

app.use((req, res, next) => {
  if (req.session) {
    req.session.csrfToken =
      req.session.csrfToken || crypto.randomBytes(16).toString("hex");
  }
  next();
});

const limiter = rateLimit({
  windowMs: parseInt(process.env.RATE_LIMIT_WINDOW_MS, 10) || 15 * 60 * 1000,
  max: parseInt(process.env.RATE_LIMIT_MAX, 10) || 100,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: "Too many requests", message: "Please slow down" },
  skip: (req) => {
    if (req.session?.authToken) return true;
    if (req.headers["x-auth-token"]) return true;
    return false;
  },
});
app.use(limiter);

app.use(express.json({ limit: "1mb" }));
app.use(express.urlencoded({ extended: true, limit: "1mb" }));

app.use(express.static(path.join(__dirname, "public")));

function validateCsrf(req, res, next) {
  if (req.path.startsWith("/api")) {
    return next();
  }
  if (req.method === "POST") {
    const token = req.body?._csrf || req.headers["x-csrf-token"];
    if (!token || !req.session?.csrfToken || token !== req.session.csrfToken) {
      if (req.headers["content-type"]?.includes("application/json")) {
        return res.status(403).json({
          error: "Forbidden",
          message: "Invalid or missing CSRF token",
        });
      }
      return res.status(403).send("Invalid or missing CSRF token");
    }
  }
  next();
}
app.use("/", validateCsrf, webRoutes);
app.use("/api", apiRoutes);

app.use(notFoundHandler);
app.use(errorHandler);

const args = process.argv.slice(2);
let PORT = process.env.PORT || 8080;
const portIndex = args.indexOf("-p");
if (portIndex !== -1 && args[portIndex + 1]) {
  PORT = parseInt(args[portIndex + 1], 10) || 8080;
}

let server;

const __filename = fileURLToPath(import.meta.url);

if (process.argv[1] === __filename) {
  server = app.listen(PORT, () => {
    logger.info(`Writer Blog running at http://localhost:${PORT}`);
    logger.info(`API docs: http://localhost:${PORT}/api`);
  });

  function gracefulShutdown(signal) {
    logger.info(`${signal} received. Shutting down gracefully...`);
    server.close(() => {
      logger.info("HTTP server closed.");
      closeDB();
      logger.info("Database closed.");
      process.exit(0);
    });
  }

  process.on("SIGTERM", () => gracefulShutdown("SIGTERM"));
  process.on("SIGINT", () => gracefulShutdown("SIGINT"));
}

export default app;
