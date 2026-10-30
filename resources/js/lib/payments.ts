export function openSaspayCheckout(url: string) {
  const destination = new URL(url);
  if (destination.protocol !== 'https:' || destination.hostname !== 'pay.saspay.me' || destination.port || destination.username || destination.password) {
    throw new Error('Adresse de paiement SasPay invalide.');
  }
  window.location.assign(destination.href);
}
