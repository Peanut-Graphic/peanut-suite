import { Router } from "express";
import { getFirestore, FieldValue } from "firebase-admin/firestore";
import { randomUUID as uuidv4 } from "node:crypto";
import { Timestamp } from "firebase-admin/firestore";
import {
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  isAccountOwner,
} from "../utils/auth";
import {
  PeanutAccount,
  AccountByUid,
  CreateAccountRequest,
  UpdateAccountRequest,
  LinkBookerRequest,
  LinkFestivalRequest,
  AccountResponse,
} from "../models/PeanutAccount";

const router = Router();
const db = getFirestore();

/**
 * POST /api/v1/account/create
 * Creates a new Peanut Account after Firebase Auth sign-in
 */
router.post(
  "/create",
  authenticateToken,
  loadPeanutAccount,
  async (req, res) => {
    try {
      // Check if account already exists
      if (req.user?.peanutId) {
        const response: AccountResponse = {
          success: true,
          peanut_id: req.user.peanutId,
          account: req.user.account,
        };
        res.json(response);
        return;
      }

      const body = req.body as CreateAccountRequest;

      // Validate required fields
      if (!body.email || !body.display_name || !body.auth_provider) {
        res.status(400).json({
          success: false,
          error: "Missing required fields: email, display_name, auth_provider",
          error_code: "PEANUT_005",
        } as AccountResponse);
        return;
      }

      const peanutId = uuidv4();
      const now = Timestamp.now();

      const newAccount: PeanutAccount = {
        peanut_id: peanutId,
        email: body.email,
        display_name: body.display_name,
        avatar_url: body.avatar_url,
        auth_providers: [body.auth_provider],
        firebase_uid: req.user!.uid,
        notebook_enabled: body.app_source === "notebook",
        email_notifications: true,
        marketing_opt_in: false,
        created_at: now,
        updated_at: now,
        last_login_at: now,
        last_app_used: body.app_source || "notebook",
      };

      const uidMapping: AccountByUid = {
        peanut_id: peanutId,
        created_at: now,
      };

      // Use batch write for atomicity
      const batch = db.batch();

      batch.set(db.collection("accounts").doc(peanutId), newAccount);
      batch.set(db.collection("accounts_by_uid").doc(req.user!.uid), uidMapping);

      await batch.commit();

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
        account: newAccount,
      };

      res.status(201).json(response);
    } catch (error) {
      console.error("Error creating account:", error);
      res.status(500).json({
        success: false,
        error: "Failed to create account",
        error_code: "PEANUT_006",
      } as AccountResponse);
    }
  },
);

/**
 * GET /api/v1/account/:peanutId
 * Get account details (owner only)
 */
router.get(
  "/:peanutId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;

      if (!isAccountOwner(req, peanutId)) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PEANUT_004",
        } as AccountResponse);
        return;
      }

      // Update last login
      await db.collection("accounts").doc(peanutId).update({
        last_login_at: Timestamp.now(),
      });

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
        account: req.user!.account,
      };

      res.json(response);
    } catch (error) {
      console.error("Error getting account:", error);
      res.status(500).json({
        success: false,
        error: "Failed to get account",
        error_code: "PEANUT_001",
      } as AccountResponse);
    }
  },
);

/**
 * PATCH /api/v1/account/:peanutId
 * Update account details (owner only)
 */
router.patch(
  "/:peanutId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;

      if (!isAccountOwner(req, peanutId)) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PEANUT_004",
        } as AccountResponse);
        return;
      }

      const body = req.body as UpdateAccountRequest;
      const updates: Partial<PeanutAccount> = {
        updated_at: Timestamp.now(),
      };

      // Only allow updating specific fields
      if (body.display_name !== undefined) {
        updates.display_name = body.display_name;
      }
      if (body.avatar_url !== undefined) {
        updates.avatar_url = body.avatar_url;
      }
      if (body.email_notifications !== undefined) {
        updates.email_notifications = body.email_notifications;
      }
      if (body.marketing_opt_in !== undefined) {
        updates.marketing_opt_in = body.marketing_opt_in;
      }

      await db.collection("accounts").doc(peanutId).update(updates);

      const updatedDoc = await db.collection("accounts").doc(peanutId).get();

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
        account: updatedDoc.data() as PeanutAccount,
      };

      res.json(response);
    } catch (error) {
      console.error("Error updating account:", error);
      res.status(500).json({
        success: false,
        error: "Failed to update account",
        error_code: "PEANUT_006",
      } as AccountResponse);
    }
  },
);

/**
 * POST /api/v1/account/:peanutId/link-booker
 * Link Peanut Account to Booker performer/customer profile
 */
router.post(
  "/:peanutId/link-booker",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;

      if (!isAccountOwner(req, peanutId)) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PEANUT_004",
        } as AccountResponse);
        return;
      }

      const body = req.body as LinkBookerRequest;

      if (!body.performer_id && !body.customer_id) {
        res.status(400).json({
          success: false,
          error: "Must provide performer_id or customer_id",
          error_code: "PEANUT_005",
        } as AccountResponse);
        return;
      }

      const updates: Partial<PeanutAccount> = {
        updated_at: Timestamp.now(),
      };

      if (body.performer_id) {
        updates.booker_performer_id = body.performer_id;
      }
      if (body.customer_id) {
        updates.booker_customer_id = body.customer_id;
      }

      await db.collection("accounts").doc(peanutId).update(updates);

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
      };

      res.json(response);
    } catch (error) {
      console.error("Error linking Booker:", error);
      res.status(500).json({
        success: false,
        error: "Failed to link Booker profile",
        error_code: "PEANUT_002",
      } as AccountResponse);
    }
  },
);

/**
 * POST /api/v1/account/:peanutId/link-festival
 * Link Peanut Account to Festival organizer role
 */
router.post(
  "/:peanutId/link-festival",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;

      if (!isAccountOwner(req, peanutId)) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PEANUT_004",
        } as AccountResponse);
        return;
      }

      const body = req.body as LinkFestivalRequest;

      if (!body.festival_id || !body.role) {
        res.status(400).json({
          success: false,
          error: "Must provide festival_id and role",
          error_code: "PEANUT_005",
        } as AccountResponse);
        return;
      }

      const updates: Partial<PeanutAccount> = {
        updated_at: Timestamp.now(),
      };

      if (body.role === "organizer") {
        updates.festival_organizer_id = body.festival_id;
      }

      await db.collection("accounts").doc(peanutId).update(updates);

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
      };

      res.json(response);
    } catch (error) {
      console.error("Error linking Festival:", error);
      res.status(500).json({
        success: false,
        error: "Failed to link Festival",
        error_code: "PEANUT_002",
      } as AccountResponse);
    }
  },
);

/**
 * DELETE /api/v1/account/:peanutId/unlink-booker
 * Unlink Peanut Account from Booker
 */
router.delete(
  "/:peanutId/unlink-booker",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;

      if (!isAccountOwner(req, peanutId)) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PEANUT_004",
        } as AccountResponse);
        return;
      }

      await db.collection("accounts").doc(peanutId).update({
        booker_performer_id: FieldValue.delete(),
        booker_customer_id: FieldValue.delete(),
        updated_at: Timestamp.now(),
      });

      const response: AccountResponse = {
        success: true,
        peanut_id: peanutId,
      };

      res.json(response);
    } catch (error) {
      console.error("Error unlinking Booker:", error);
      res.status(500).json({
        success: false,
        error: "Failed to unlink Booker profile",
        error_code: "PEANUT_006",
      } as AccountResponse);
    }
  },
);

export default router;
