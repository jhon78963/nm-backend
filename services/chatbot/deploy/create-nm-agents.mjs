#!/usr/bin/env node
/**
 * Creates or updates NM Maritex chat agents in PostgreSQL (chat_agents).
 *
 * Usage (from services/chatbot):
 *   npm run create:agents:nm
 *   node --env-file=.env deploy/create-nm-agents.mjs
 *
 * Optional env:
 *   NM_AGENT_PASSWORD=nm2026!
 */

import bcrypt from 'bcryptjs';
import { prisma, disconnectPrisma } from './prisma-client.mjs';

const BCRYPT_ROUNDS = 10;
const DEFAULT_PASSWORD = process.env.NM_AGENT_PASSWORD ?? 'nm2026!';

/** Fixed IDs for idempotent upserts (match chatbot-seed admin id). */
const NM_AGENTS = [
  {
    id: 'nm-chatbot-admin-001',
    userId: 'nm-chatbot-admin-001',
    username: 'admin',
    email: 'admin@novedadesmaritex.net.pe',
    name: 'Admin NM',
    whatsapp: '+51999999999',
    role: 'admin',
  },
  {
    id: 'nm-agent-zero-001',
    userId: 'nm-agent-zero-001',
    username: 'zero',
    email: 'zero@novedadesmaritex.net.pe',
    name: 'Zero',
    whatsapp: '+51999999999',
    role: 'admin',
  },
  {
    id: 'nm-agent-maritex-001',
    userId: 'nm-agent-maritex-001',
    username: 'maritex',
    email: 'maritex@novedadesmaritex.net.pe',
    name: 'Maritex',
    whatsapp: '+51999999999',
    role: 'admin',
  },
  {
    id: 'nm-agent-asesor-001',
    userId: 'nm-agent-asesor-001',
    username: 'asesor1.nm',
    email: 'asesor1@novedadesmaritex.net.pe',
    name: 'Asesor 1 NM',
    whatsapp: '+51999999901',
    role: 'agent',
  },
  {
    id: 'nm-agent-asesor-002',
    userId: 'nm-agent-asesor-002',
    username: 'asesor2.nm',
    email: 'asesor2@novedadesmaritex.net.pe',
    name: 'Asesor 2 NM',
    whatsapp: '+51999999902',
    role: 'agent',
  },
];

if (!process.env.DATABASE_URL) {
  console.error('Error: DATABASE_URL no está definida en .env');
  process.exit(1);
}

const passwordHash = await bcrypt.hash(DEFAULT_PASSWORD, BCRYPT_ROUNDS);

console.log('NM Maritex — creando/actualizando agentes en PostgreSQL...\n');

for (const agent of NM_AGENTS) {
  await prisma.chatAgent.upsert({
    where: { email: agent.email },
    create: {
      id: agent.id,
      userId: agent.userId,
      name: agent.name,
      email: agent.email,
      whatsapp: agent.whatsapp,
      status: 'Active',
      username: agent.username,
      passwordHash,
      role: agent.role,
    },
    update: {
      name: agent.name,
      status: 'Active',
      username: agent.username,
      passwordHash,
      role: agent.role,
      whatsapp: agent.whatsapp,
    },
  });

  console.log(`✅ ${agent.role.padEnd(5)} | ${agent.username} | ${agent.email}`);
}

console.log('\n─────────────────────────────────────────');
console.log('CREDENCIALES INICIALES (cambiar tras primer login):');
console.log('─────────────────────────────────────────');
for (const agent of NM_AGENTS) {
  console.log(`  ${agent.username} / ${DEFAULT_PASSWORD}  (${agent.name})`);
}
console.log('\nPanel admin: http://localhost:8090/admin (dev) o vía nm-frontend-v2 /chatbot');

await disconnectPrisma();
console.log('\nDone.');
