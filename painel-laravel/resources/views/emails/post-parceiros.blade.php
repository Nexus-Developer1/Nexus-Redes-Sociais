{{--
  Post enviado por email a um parceiro, no modelo da newsletter da NEXUS (o mesmo das campanhas do E-goi):
  cabeçalho verde, imagem do post, título a verde, texto, "WE MAKE IT FOR YOU", produtos, "Torne-se um parceiro",
  marcas, contactos e redes. Tabelas e estilos na própria tag (Outlook). As imagens vão DENTRO do email
  ($message->embed): os parceiros não chegam ao servidor interno. Peças em resources/email-parceiros/.
--}}
@php
    $verde = '#00BA4C';
    $escuro = '#284E36';
    $texto = '#4B4B4B';
    $fonte = 'Helvetica, Arial, sans-serif';
    $primeiroNome = $nome ? trim(strtok($nome, ' ')) : null;
    $peca = fn (string $f) => $message->embed(resource_path('email-parceiros/'.$f));
    $redes = [
        ['linkedin.png', 'https://www.linkedin.com/company/nexussolutions/', 'LinkedIn'],
        ['facebook.png', 'https://www.facebook.com/nexusolutions', 'Facebook'],
        ['instagram.png', 'https://www.instagram.com/nexus.solutions/', 'Instagram'],
        ['youtube.png', 'https://www.youtube.com/c/NEXUSsolutionsptglobal', 'YouTube'],
    ];
@endphp
<!doctype html>
<html lang="pt-PT" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $post->assuntoParceiros() }}</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<style>
  img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
  @media screen and (max-width: 600px) { .caixa { width: 100% !important; } .fluida { width: 100% !important; height: auto !important; } }
</style>
</head>
<body style="margin:0;padding:0;background:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
<tr><td align="center" style="padding:10px 0;">

@if ($teste)
    <table role="presentation" class="caixa" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
        <tr><td style="background:#fff4d6;border:1px solid #f0d98a;padding:10px 14px;font-family:{{ $fonte }};font-size:13px;color:#6b4a00;">
            <b>Email de teste.</b> É assim que os parceiros recebem este post. Só foi enviado para si.
        </td></tr>
        <tr><td height="10" style="font-size:0;line-height:0;">&nbsp;</td></tr>
    </table>
@endif

<table role="presentation" class="caixa" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;">

    {{-- Cabeçalho verde: NEXUS · WE MAKE IT FOR YOU --}}
    <tr><td style="padding:0;font-size:0;line-height:0;">
        <a href="https://nexus-solutions.pt/" target="_blank"><img class="fluida" src="{{ $peca('cabecalho.jpg') }}" width="600" alt="NEXUS · We make IT for you." style="display:block;width:600px;"></a>
    </td></tr>

    {{-- Imagem do post (num carrossel, todas por ordem) --}}
    @foreach ($imagens as $i => $img)
        <tr><td align="center" style="padding:{{ $i ? '12px' : '0' }} 40px 0;font-size:0;line-height:0;">
            <img class="fluida" src="{{ $message->embed($img) }}" width="520" alt="{{ $i ? 'Imagem '.($i + 1).' do post' : ($post->image_alt ?: $post->assuntoParceiros()) }}"
                 style="display:block;width:520px;max-width:100%;">
        </td></tr>
    @endforeach

    {{-- Título a verde --}}
    <tr><td style="padding:22px 20px 6px;font-family:{{ $fonte }};font-size:24px;line-height:30px;font-weight:bold;color:{{ $verde }};text-align:center;">
        {{ $post->assuntoParceiros() }}
    </td></tr>

    {{-- Texto: a legenda do post, sem as hashtags --}}
    <tr><td style="padding:14px 40px 0;font-family:{{ $fonte }};font-size:14px;line-height:150%;color:{{ $texto }};text-align:left;">
        @if ($primeiroNome)Olá {{ $primeiroNome }},<br><br>@endif
        {!! nl2br(e($legenda)) !!}
    </td></tr>

    {{-- WE MAKE IT FOR YOU --}}
    <tr><td style="padding:34px 0 0;font-size:0;line-height:0;">
        <img class="fluida" src="{{ $peca('we-make-it.png') }}" width="600" alt="We make IT for you" style="display:block;width:600px;">
    </td></tr>

    {{-- Produtos --}}
    <tr><td style="padding:0;font-size:0;line-height:0;">
        <a href="https://configurador.nexus-solutions.pt/" target="_blank"><img class="fluida" src="{{ $peca('produtos.png') }}" width="600" alt="Servidores, Workstation, Computadores, Mini PC, UPS, Bastidores, POS" style="display:block;width:600px;"></a>
    </td></tr>

    {{-- Torne-se um parceiro --}}
    <tr><td style="padding:30px 20px 0;font-family:{{ $fonte }};font-size:14px;line-height:200%;color:{{ $texto }};text-align:center;">
        <span style="color:{{ $escuro }};"><strong>TORNE-SE UM PARCEIRO NEXUS</strong></span><br>
        Obtenha acesso privilegiado a um portefólio alargado de produtos e serviços.
    </td></tr>
    <tr><td align="center" style="padding:16px 20px 30px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td bgcolor="{{ $verde }}" style="background:{{ $verde }};padding:9px 14px;">
                <a href="https://nexus-solutions.pt/programa-parceiros-nexus/" target="_blank"
                   style="font-family:{{ $fonte }};font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;">VER CONDIÇÕES</a>
            </td>
        </tr></table>
    </td></tr>

    {{-- Marcas --}}
    <tr><td style="padding:0;font-size:0;line-height:0;">
        <a href="https://nexus-solutions.pt/" target="_blank"><img class="fluida" src="{{ $peca('marcas.jpg') }}" width="600" alt="Providing technology: NVIDIA, AMD, Supermicro, Intel, Riello UPS, Ubiquiti, Micron, Toshiba, Arcserve, DataCore, Philips, AOC" style="display:block;width:600px;"></a>
    </td></tr>

    {{-- Contactos --}}
    <tr><td style="padding:16px 20px;font-family:{{ $fonte }};font-size:12px;color:#999999;text-align:center;">
        <a href="https://nexus-solutions.pt/" target="_blank" style="color:{{ $escuro }};text-decoration:none;"><strong>www.nexus-solutions.pt</strong></a>
        &nbsp;|&nbsp; <a href="mailto:info@nexus-solutions.pt" style="color:{{ $escuro }};text-decoration:none;"><strong>info@nexus-solutions.pt</strong></a>
        &nbsp;|&nbsp; <span style="color:{{ $escuro }};"><strong>(+351) 229 437 980</strong></span>
    </td></tr>

    {{-- Rodapé verde-claro: redes e como deixar de receber --}}
    <tr><td bgcolor="#C1D59F" style="background:#C1D59F;padding:22px 20px 20px;" align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
            @foreach ($redes as $k => [$icone, $url, $nomeRede])
                @if ($k)<td width="20" style="font-size:0;line-height:0;">&nbsp;</td>@endif
                <td><a href="{{ $url }}" target="_blank"><img src="{{ $peca($icone) }}" width="32" height="32" alt="{{ $nomeRede }}" style="display:block;width:32px;height:32px;"></a></td>
            @endforeach
        </tr></table>
        <p style="margin:16px 0 0;font-family:{{ $fonte }};font-size:12px;line-height:18px;color:{{ $escuro }};text-align:center;">
            Recebe este email por ser parceiro da NEXUS IT Solutions.<br>
            <a href="{{ $remover }}" style="color:{{ $escuro }};text-decoration:underline;">Remover</a>
        </p>
    </td></tr>
</table>

</td></tr>
</table>
</body>
</html>
