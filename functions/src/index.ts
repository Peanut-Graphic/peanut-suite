import { initializeApp } from "firebase-admin/app";
import { onRequest } from "firebase-functions/v2/https";
import express from "express";
import cors from "cors";

// Initialize Firebase Admin
initializeApp();

// Import API routers
import accountRouter from "./api/account";
import performerRouter from "./api/performer";
import venueRouter from "./api/venue";
import bookingRouter from "./api/booking";

// Create Express app
const app = express();

// Middleware
app.use(cors({ origin: true }));
app.use(express.json());

// Health check
app.get("/api/v1/health", (req, res) => {
  res.json({
    status: "ok",
    service: "peanut-suite-api",
    version: "1.0.1",
    timestamp: new Date().toISOString(),
  });
});

// Mount API routes
app.use("/api/v1/account", accountRouter);
app.use("/api/v1/performer", performerRouter);
app.use("/api/v1/venue", venueRouter);
app.use("/api/v1/booking", bookingRouter);

// 404 handler
app.use((req, res) => {
  res.status(404).json({
    success: false,
    error: "Endpoint not found",
    error_code: "API_404",
  });
});

// Error handler
app.use((err: Error, req: express.Request, res: express.Response, _next: express.NextFunction) => {
  console.error("Unhandled error:", err);
  res.status(500).json({
    success: false,
    error: "Internal server error",
    error_code: "API_500",
  });
});

// Export the Express app as a Cloud Function (public access for API)
export const api = onRequest({ invoker: "public" }, app);
