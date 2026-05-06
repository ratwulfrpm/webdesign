# Cloud Plan

## Target: AWS (recomendado)

## Core Services

- EC2 / ECS → App
- RDS → DB
- S3 → storage
- CloudFront → CDN
- API Gateway (opcional)
- WAF → protección

---

## Storage Migration

Actual:
- filesystem local

Futuro:
- S3 / R2

Cambios:
- usar adapter
- signed URLs
- no exponer rutas reales

---

## Security

- Rate limiting
- WAF rules
- HTTPS obligatorio
- Secrets en:
  - AWS Secrets Manager

---

## Scaling

- Auto Scaling Group
- Load Balancer
- Stateless sessions (futuro: Redis)

---

## CI/CD

- GitHub Actions
- Deploy automático
- Environment separation:
  - dev
  - staging
  - prod

---

## Payments

- Stripe / PayPal
- Webhooks
- Validación server-side

---

## Logs

- CloudWatch
- Sin datos sensibles

---

## Future Enhancements

- HTML sanitizer avanzado
- Multi-region
- Failover DB