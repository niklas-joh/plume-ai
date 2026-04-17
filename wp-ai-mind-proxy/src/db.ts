import type { Env, User } from './types';

export async function getUserByEmail(email: string, env: Env): Promise<User | null> {
  return env.DB.prepare(`SELECT * FROM users WHERE email = ?`)
    .bind(email.toLowerCase().trim()).first<User>();
}

export async function getUserById(id: number, env: Env): Promise<User | null> {
  return env.DB.prepare(`SELECT * FROM users WHERE id = ?`).bind(id).first<User>();
}

export async function updateUserPlan(
  email: string,
  plan: 'free' | 'trial' | 'pro_managed' | 'pro',
  planExpires: string | null,
  env: Env
): Promise<void> {
  await env.DB.prepare(
    `UPDATE users SET plan = ?, plan_expires = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?`
  ).bind(plan, planExpires, email.toLowerCase().trim()).run();
}
