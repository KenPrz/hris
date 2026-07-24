import { redirect } from 'next/navigation'

/**
 * The root is not a screen. An authenticated user belongs on their attendance; an
 * unauthenticated one is bounced to `/login` by the (app) guard. The system health view
 * that used to live here moved to `/health`, off the path a person actually types.
 */
export default function Home() {
  redirect('/me/attendance')
}
