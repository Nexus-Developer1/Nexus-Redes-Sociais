{{--
  Post enviado por email a um parceiro, com a cara da NEXUS. Tabelas e estilos na própria tag (Outlook).
  As imagens vão dentro do email ($message->embed): os parceiros não chegam ao servidor interno.
--}}
@php
    $verde = '#00b147';
    $verdeEscuro = '#0b6e31';
    $escuro = '#0b1a10';
    $texto = '#1d2b22';
    $cinza = '#5c6e62';
    $fonte = "'Segoe UI', Arial, Helvetica, sans-serif";
    $primeiroNome = $nome ? trim(strtok($nome, ' ')) : null;
@endphp
<!doctype html>
<html lang="pt-PT" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $post->assuntoParceiros() }}</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
</head>
<body style="margin:0;padding:0;background:#eef2ef;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2ef;">
<tr><td align="center" style="padding:28px 12px;">

@if ($teste)
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
        <tr><td style="background:#fff4d6;border:1px solid #f0d98a;border-radius:8px;padding:10px 14px;font-family:{{ $fonte }};font-size:13px;color:#6b4a00;">
            <b>Email de teste.</b> É assim que os parceiros recebem este post. Só foi enviado para si.
        </td></tr>
        <tr><td height="14" style="font-size:0;line-height:0;">&nbsp;</td></tr>
    </table>
@endif

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

    {{-- Cabeçalho escuro com o logótipo --}}
    <tr><td style="background:{{ $escuro }};padding:24px 32px;">
        @if ($logo)
            <img src="{{ $message->embed($logo) }}" width="150" height="28" alt="NEXUS" style="display:block;border:0;">
        @else
            <span style="font-family:{{ $fonte }};font-size:26px;font-weight:700;color:{{ $verde }};letter-spacing:2px;">NEXUS</span>
        @endif
    </td></tr>
    <tr><td style="background:{{ $verde }};height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>

    <tr><td style="padding:28px 32px 0;font-family:{{ $fonte }};font-size:15px;line-height:22px;color:{{ $texto }};">
        Olá{{ $primeiroNome ? ' '.$primeiroNome : '' }},<br>
        partilhamos consigo a nossa publicação mais recente.
    </td></tr>

    {{-- Imagem do post (num carrossel, todas por ordem) --}}
    @foreach ($imagens as $i => $img)
        <tr><td align="center" style="padding:{{ $i ? '12px' : '22px' }} 32px 0;">
            <img src="{{ $message->embed($img) }}" width="536" alt="{{ $i ? 'Imagem '.($i + 1).' do post' : ($post->image_alt ?: $post->assuntoParceiros()) }}"
                 style="display:block;width:536px;max-width:100%;height:auto;border:0;border-radius:8px;">
        </td></tr>
    @endforeach

    {{-- Texto (a legenda sem as hashtags) --}}
    @if ($legenda !== '')
        <tr><td style="padding:22px 32px 0;font-family:{{ $fonte }};font-size:15px;line-height:23px;color:{{ $texto }};">
            {!! nl2br(e($legenda)) !!}
        </td></tr>
    @endif

    {{-- Botão para o site --}}
    <tr><td align="center" style="padding:28px 32px 0;">
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="https://nexus-solutions.pt"
            style="height:48px;v-text-anchor:middle;width:240px;" arcsize="16%" strokecolor="{{ $verde }}" fillcolor="{{ $verde }}">
            <w:anchorlock/>
            <center style="color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:bold;">Visitar nexus-solutions.pt</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-- -->
        <a href="https://nexus-solutions.pt" target="_blank"
           style="display:inline-block;padding:0 26px;line-height:48px;background:{{ $verde }};border-radius:8px;font-family:{{ $fonte }};font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;mso-hide:all;">Visitar nexus-solutions.pt</a>
        <!--<![endif]-->
    </td></tr>
    <tr><td align="center" style="padding:14px 32px 0;font-family:{{ $fonte }};font-size:14px;line-height:21px;color:{{ $cinza }};">
        Alguma questão? Responda a este email ou ligue para o 229 437 980.
    </td></tr>

    <tr><td style="padding:26px 32px 0;"><div style="border-top:1px solid #e3e9e5;font-size:0;line-height:0;">&nbsp;</div></td></tr>
    <tr><td align="center" style="padding:6px 32px 26px;font-family:{{ $fonte }};font-size:12px;line-height:19px;color:{{ $cinza }};">
        Recebe este email por ser parceiro da NEXUS IT Solutions.<br>
        Não quer receber mais? <a href="{{ $remover }}" style="color:{{ $verdeEscuro }};font-weight:700;">Peça para ser removido</a> ou responda a este email.
    </td></tr>
</table>

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
    <tr><td align="center" style="padding:16px 12px 0;font-family:{{ $fonte }};font-size:11px;line-height:17px;color:#8a998f;">
        NEXUS IT Solutions · nexus-solutions.pt · 229 437 980
    </td></tr>
</table>

</td></tr>
</table>
</body>
</html>
