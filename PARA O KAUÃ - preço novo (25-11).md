# Para o Kauã · preço novo do webinar (25/11)

Novos preços:
- **Ingresso (acesso às aulas): R$ 89,90** (era R$ 47,90)
- **Ingresso + Comunidade: R$ 97,90** (era R$ 57,90)

A página já está pronta na branch **`preco-97-90`** (textos, botões, chat e valor do Pixel nas páginas de obrigado). Falta só o que depende de você:

1. **Stripe:** criar os 2 links de pagamento novos (R$ 89,90 e R$ 97,90), com o mesmo redirecionamento para as páginas de obrigado dos links atuais.
2. **`index.html`** (objeto `CHECKOUT`, linhas ~1197-1198): trocar as URLs `buy.stripe.com/...` pelos links novos.
3. **`stripe-compra-dcdeb41db1bc54d823aaee60.php`** (lista `$LINKS`): incluir os `plink_...` dos links novos (Básico e Plus). Sem isso, a compra não chega no Zoho.
4. **`stripe-webhook.php`** (mapa de valores, linhas ~83-84): incluir `8990 => 'Imersao Ambar - Ingresso'` e `9790 => 'Imersao Ambar - Ingresso + Comunidade'`.
5. **Zoho:** a regra "Webinar · E1 confirmação da compra" usa o campo `Stripe_valor_centavos` (4790/5790). Incluir 8990 e 9790, senão o comprador não recebe o e-mail de confirmação.
6. **Publicar:** juntar a `preco-97-90` na `main` e subir no cPanel. Não apagar o `stripe-compra-dcdeb41db1bc54d823aaee60.php`.
7. **Teste:** uma compra de teste em cada link (depois reembolsar), conferindo que chega no Zoho, dispara o e-mail E1 e o Purchase na Meta.

Ainda pendente (o Rafael vai definir data, horário e preço): as páginas da aula bônus (`sabado-...html` e `domingo-...html`) ainda dizem domingo, 06/12.
