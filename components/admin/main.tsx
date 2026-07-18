/**
 * Entry point for the wp-admin session-editor bundle. Built by
 * scripts/build-admin-bundle.mjs into wordpress-plugin/admin/dist/
 * (chronicler-admin.js + chronicler-admin.css); the mount/boot contract lives
 * in mount.tsx and apiFetch.ts. Kept to a bare side-effect call so tests can
 * import the mount logic without triggering it.
 */
import { mountWhenReady } from "@/components/admin/mount";

mountWhenReady();
