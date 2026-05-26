import logger from "../utils/logger.js";

function errorHandler(err, req, res, next) {
  logger.error("Error:", err.message);
  logger.error("Stack:", err.stack);

  if (err.name === "ValidationError") {
    return res.status(400).json({
      error: "Validation Error",
      message: err.message,
    });
  }

  if (err.name === "NotFoundError") {
    return res.status(404).json({
      error: "Not Found",
      message: err.message,
    });
  }

  const status = err.status || err.statusCode || 500;

  res.status(status).json({
    error: status === 500 ? "Internal Server Error" : err.name || "Error",
    message:
      process.env.NODE_ENV === "development" || status !== 500
        ? err.message
        : "Something went wrong",
  });
}

function notFoundHandler(req, res) {
  res.status(404).render("404", {
    message: `The page "${req.path}" could not be found.`,
    settings: { title: "Page Not Found - Writer" },
  });
}

export {
  errorHandler,
  notFoundHandler,
};
