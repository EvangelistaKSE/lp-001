Kauã, mudamos os preços do webinar e a data da aula bônus. As páginas já estão prontas na branch **preco-97-90** do repositório. Preciso que você faça tudo o que está abaixo para publicar, deixando o pagamento e o CRM funcionando de ponta a ponta.

**Preços novos**
• Ingresso (acesso às aulas): R$ 89,90 (era R$ 47,90)
• Ingresso + Comunidade: R$ 97,90 (era R$ 57,90)
• Aula bônus: quinta, 10/12, às 20h, R$ 47,90 (era domingo, R$ 89,90). Com cupom: 30% = R$ 33,53 · 15% = R$ 40,71

**1. Stripe:** criar 3 links de pagamento novos:
• Ingresso, R$ 89,90
• Ingresso + Comunidade, R$ 97,90
• Aula bônus, R$ 47,90, aceitando os cupons BONUSSABADO30 (30%, 10 usos) e EXCECAO15 (15%)
Usar o mesmo redirecionamento dos links atuais para as páginas de obrigado.

**2. index.html** (objeto CHECKOUT, linhas ~1197-1198): trocar as 2 URLs buy.stripe.com pelos links novos do ingresso e do ingresso + comunidade.

**3. sabado-uui52xxfwu.html:** trocar a URL buy.stripe.com/eVq3... nos 3 botões pelo link novo da aula bônus, mantendo o ?prefilled_promo_code de cada um.

**4. stripe-compra-dcdeb41db1bc54d823aaee60.php** (lista $LINKS): incluir os plink_ dos 3 links novos (Básico, Plus e aula bônus). Sem isso, a compra não chega no Zoho.

**5. stripe-webhook.php** (mapa de valores, linhas ~83-84): incluir 8990 => 'Imersao Ambar - Ingresso' e 9790 => 'Imersao Ambar - Ingresso + Comunidade'.

**6. Valor 4790:** a aula bônus agora custa o mesmo que o ingresso antigo (R$ 47,90). Hoje, no webhook e na regra E1 do Zoho, 4790 = "Ingresso". Se ficar assim, quem comprar a aula bônus vai ser registrado como ingresso e receber o e-mail de confirmação do webinar. Trocar para 4790 = aula bônus, ou identificar pelo link e não pelo valor.

**7. Zoho · e-mails:** na regra "Webinar · E1 confirmação da compra" (campo Stripe_valor_centavos), incluir 8990 e 9790, e ajustar o 4790 conforme o item 6.

**8. CRM · leads da LP (a campanha da Meta vai mandar tráfego para a LP):**
• Conferir se a assinatura do Zoho está ativa (vencia em 24/11).
• Conferir se o Zoho Flow está ligado e recebendo do zoho.php no módulo "Webinar - Alavancagem em 2027".
• Formulário da LP → card criado no Zoho com nome, e-mail, WhatsApp, origem/UTM e etapa "Página Acessada".
• Formulário de qualificação (/qualificacao) → atualiza o mesmo card.
• Compra na Stripe → atualiza o mesmo card para "Ingresso Comprado", com o ingresso certo (Básico ou Plus) e o valor novo.
• Aula bônus → status "Comprou aula de domingo" (pode renomear para quinta).
• Testar com um e-mail que não seja da equipe e sem ?teste=1, porque esses são marcados como TESTE e não disparam o Lead.

**9. Pixel e CAPI:** conferir que o Lead (formulário da LP) e o Purchase (compras, com os valores novos) chegam no conjunto de dados 1139198742028778 do portfólio Âmbar Alternative, e que o domínio ambaralternativewebinar.com.br aparece como verificado.

**10. Publicar:** juntar a branch preco-97-90 na main e subir no cPanel. Não apagar o stripe-compra-dcdeb41db1bc54d823aaee60.php.

**11. Teste final:** fazer uma compra em cada um dos 3 links e reembolsar depois. Para cada uma, conferir:
• se chega no Zoho com o ingresso certo;
• se o e-mail certo dispara;
• se o Purchase aparece na Meta.
Fazer também um cadastro de teste pela LP e conferir se o card aparece no Zoho.

Quando terminar, me avisa para eu conferir a página no ar.
