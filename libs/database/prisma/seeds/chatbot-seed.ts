import { PrismaClient } from '@prisma/client';
import * as bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

const ADMIN_ID = 'nm-chatbot-admin-001';

const QUICK_REPLIES = [
  {
    id: 'nm-qr-001',
    title: 'Saludo',
    body: '¡Hola! Soy [nombre] de Novedades Maritex 👗. ¿En qué puedo ayudarte hoy?',
  },
  {
    id: 'nm-qr-002',
    title: 'Revisando consulta',
    body: 'Estoy revisando tu consulta, dame un momento por favor 😊',
  },
  {
    id: 'nm-qr-003',
    title: 'Sitio web',
    body: 'Para más información visita: https://novedadesmaritex.net.pe',
  },
  {
    id: 'nm-qr-004',
    title: 'Pedido en proceso',
    body: 'Tu pedido está siendo atendido. Te confirmo en breve ✅',
  },
  {
    id: 'nm-qr-005',
    title: 'Despedida',
    body: '¡Gracias por contactar a Novedades Maritex! ¿Puedo ayudarte en algo más? 🌟',
  },
] as const;

async function main(): Promise<void> {
  const passwordHash = await bcrypt.hash('nm2026!', 10);

  await prisma.chatAgent.upsert({
    where: { email: 'admin@novedadesmaritex.net.pe' },
    create: {
      id: ADMIN_ID,
      userId: ADMIN_ID,
      name: 'Admin NM',
      email: 'admin@novedadesmaritex.net.pe',
      whatsapp: '+51999999999',
      status: 'Active',
      username: 'admin',
      role: 'admin',
      passwordHash,
    },
    update: {
      name: 'Admin NM',
      status: 'Active',
      username: 'admin',
      role: 'admin',
      passwordHash,
    },
  });

  for (const qr of QUICK_REPLIES) {
    await prisma.chatQuickReply.upsert({
      where: { id: qr.id },
      create: {
        id: qr.id,
        title: qr.title,
        body: qr.body,
        createdBy: ADMIN_ID,
      },
      update: {
        title: qr.title,
        body: qr.body,
      },
    });
  }

  console.log('Chatbot seed OK — admin@novedadesmaritex.net.pe / admin / nm2026!');
}

main()
  .catch((err: unknown) => {
    console.error(err);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
