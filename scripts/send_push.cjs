const webpush = require('web-push');

async function main() {
  const args = process.argv.slice(2);
  if (args.length < 3) {
    console.error(JSON.stringify({ error: 'Usage: node send_push.js <subscriptionJson> <payloadJson> <vapidJson>' }));
    process.exit(1);
  }

  try {
    const subscription = JSON.parse(args[0]);
    const payload = args[1];
    const vapid = JSON.parse(args[2]);

    webpush.setVapidDetails(
      vapid.subject || 'mailto:admin@example.com',
      vapid.publicKey,
      vapid.privateKey
    );

    const result = await webpush.sendNotification(
      subscription,
      typeof payload === 'string' ? payload : JSON.stringify(payload)
    );

    console.log(JSON.stringify({ success: true, statusCode: result.statusCode }));
  } catch (err) {
    console.error(JSON.stringify({
      success: false,
      error: err.message,
      statusCode: err.statusCode
    }));
    process.exit(1);
  }
}

main();
