import * as admin from "firebase-admin";
import * as crypto from "crypto";
import { Request, Response, NextFunction } from "express";
import { PeanutAccount } from "../models/PeanutAccount";

// Extend Express Request to include authenticated user
declare global {
  namespace Express {
    interface Request {
      user?: {
        uid: string;
        peanutId?: string;
        account?: PeanutAccount;
      };
    }
  }
}

/**
 * Middleware to authenticate Firebase ID token
 * Sets req.user.uid on success
 */
export const authenticateToken = async (
  req: Request,
  res: Response,
  next: NextFunction,
): Promise<void> => {
  const authHeader = req.headers.authorization;

  if (!authHeader?.startsWith("Bearer ")) {
    res.status(401).json({
      success: false,
      error: "Missing or invalid authorization header",
      error_code: "PEANUT_003",
    });
    return;
  }

  const token = authHeader.split("Bearer ")[1];

  try {
    const decodedToken = await admin.auth().verifyIdToken(token);
    req.user = {
      uid: decodedToken.uid,
    };
    next();
  } catch (error) {
    console.error("Token verification failed:", error);
    res.status(401).json({
      success: false,
      error: "Invalid auth token",
      error_code: "PEANUT_003",
    });
  }
};

/**
 * Middleware to load Peanut Account for authenticated user
 * Must be used after authenticateToken
 * Sets req.user.peanutId and req.user.account on success
 */
export const loadPeanutAccount = async (
  req: Request,
  res: Response,
  next: NextFunction,
): Promise<void> => {
  if (!req.user?.uid) {
    res.status(401).json({
      success: false,
      error: "Not authenticated",
      error_code: "PEANUT_003",
    });
    return;
  }

  try {
    const db = admin.firestore();

    // Look up Peanut ID from UID mapping
    const uidDoc = await db
      .collection("accounts_by_uid")
      .doc(req.user.uid)
      .get();

    if (!uidDoc.exists) {
      // User is authenticated but doesn't have a Peanut Account yet
      // This is OK for account creation endpoint
      next();
      return;
    }

    const uidData = uidDoc.data();
    const peanutId = uidData?.peanut_id;

    if (!peanutId) {
      next();
      return;
    }

    // Load full account
    const accountDoc = await db.collection("accounts").doc(peanutId).get();

    if (!accountDoc.exists) {
      // Inconsistent state - UID mapping exists but account doesn't
      console.error(`Account ${peanutId} not found for UID ${req.user.uid}`);
      res.status(500).json({
        success: false,
        error: "Account data inconsistent",
        error_code: "PEANUT_001",
      });
      return;
    }

    req.user.peanutId = peanutId;
    req.user.account = accountDoc.data() as PeanutAccount;
    next();
  } catch (error) {
    console.error("Error loading Peanut Account:", error);
    res.status(500).json({
      success: false,
      error: "Failed to load account",
      error_code: "PEANUT_001",
    });
  }
};

/**
 * Middleware to require a Peanut Account exists
 * Must be used after loadPeanutAccount
 */
export const requirePeanutAccount = (
  req: Request,
  res: Response,
  next: NextFunction,
): void => {
  if (!req.user?.peanutId || !req.user?.account) {
    res.status(404).json({
      success: false,
      error: "Peanut Account not found",
      error_code: "PEANUT_001",
    });
    return;
  }
  next();
};

/**
 * Check if the authenticated user owns a specific Peanut Account
 */
export const isAccountOwner = (req: Request, peanutId: string): boolean => {
  return req.user?.peanutId === peanutId;
};

/**
 * Verify webhook signature from Booker or Festival
 */
export const verifyWebhookSignature = (
  payload: string,
  signature: string,
  secret: string,
): boolean => {
  const expectedSignature = crypto
    .createHmac("sha256", secret)
    .update(payload)
    .digest("hex");

  try {
    return crypto.timingSafeEqual(
      Buffer.from(signature),
      Buffer.from(expectedSignature),
    );
  } catch {
    return false;
  }
};
