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

## Aula bônus (também na branch `preco-97-90`)

Nova aula bônus: **quinta, 10/12, às 20h, R$ 47,90**. As páginas `sabado-...html` (oferta) e `domingo-...html` (confirmação) já estão com a data, o horário e os valores novos: 30% off = R$ 33,53 · 15% off = R$ 40,71. O resgate abre na quarta, 09/12, às 22h30, e fecha na quinta, às 19h45.

8. **Stripe:** criar o link novo da aula bônus de **R$ 47,90**, aceitando os cupons BONUSSABADO30 (30%, 10 usos) e EXCECAO15 (15%), e com o redirecionamento para a página `domingo-...html`. Trocar a URL `buy.stripe.com/eVq3...` nos 3 botões da `sabado-...html`.
9. **`stripe-compra-...php`:** incluir o `plink_` do link novo da aula bônus (status "Comprou aula de domingo", ou renomear para quinta).
10. **Atenção ao valor 4790:** a aula bônus agora custa o mesmo que o ingresso antigo (R$ 47,90). No `stripe-webhook.php` e na regra E1 do Zoho, o 4790 hoje significa "Ingresso". Se ficar assim, quem comprar a aula bônus vai ser registrado como ingresso e receber o e-mail de confirmação do webinar. Trocar o mapeamento: 4790 = aula bônus (ou identificar pelo link, e não pelo valor).
