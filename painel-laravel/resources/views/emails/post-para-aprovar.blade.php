{{--
  Email ao aprovador, com a cara da NEXUS. Feito com tabelas e estilos na própria tag
  porque o Outlook ignora quase todo o CSS moderno. Imagens dentro do email: $message->embed().
--}}
@php
    $verde = '#00b147';
    $verdeEscuro = '#0b6e31';
    $escuro = '#0b1a10';
    $texto = '#1d2b22';
    $cinza = '#5c6e62';
    $fonte = "'Segoe UI', Arial, Helvetica, sans-serif";
@endphp
<!doctype html>
<html lang="pt-PT" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Post para aprovar</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
</head>
<body style="margin:0;padding:0;background:#eef2ef;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2ef;">
<tr><td align="center" style="padding:28px 12px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

    {{-- Cabeçalho escuro com o logótipo --}}
    <tr><td style="background:{{ $escuro }};padding:24px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle">
                @if ($logo)
                    <img src="{{ $message->embed($logo) }}" width="150" height="28" alt="NEXUS" style="display:block;border:0;">
                @else
                    <span style="font-family:{{ $fonte }};font-size:26px;font-weight:700;color:{{ $verde }};letter-spacing:2px;">NEXUS</span>
                @endif
            </td>
            <td valign="middle" align="right" style="font-family:{{ $fonte }};font-size:11px;font-weight:600;letter-spacing:3px;color:#8fd3a5;">
                REDES SOCIAIS
            </td>
        </tr></table>
    </td></tr>
    <tr><td style="background:{{ $verde }};height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>

    {{-- Título --}}
    <tr><td style="padding:30px 32px 6px;font-family:{{ $fonte }};">
        <div style="font-size:12px;font-weight:700;letter-spacing:2px;color:{{ $verde }};">POST PARA APROVAR</div>
        <div style="margin-top:8px;font-size:24px;line-height:30px;font-weight:700;color:{{ $texto }};">{{ $post->title }}</div>
        <div style="margin-top:8px;font-size:14px;line-height:20px;color:{{ $cinza }};">
            Olá{{ $nome ? ' '.$nome : '' }}, este post está à espera da sua aprovação.
        </div>
    </td></tr>

    {{-- Data, redes e tipo --}}
    <tr><td style="padding:14px 32px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="font-family:{{ $fonte }};font-size:13px;color:{{ $texto }};background:#eef7f1;border-radius:6px;padding:6px 12px;">
                <b>{{ $post->whenLabel() }}</b>
            </td>
            <td width="8">&nbsp;</td>
            <td style="font-family:{{ $fonte }};font-size:13px;color:{{ $texto }};background:#eef7f1;border-radius:6px;padding:6px 12px;">
                {{ implode(' · ', $post->networks ?? []) }}
            </td>
            @if ($post->imagens_carrossel)
                <td width="8">&nbsp;</td>
                <td style="font-family:{{ $fonte }};font-size:13px;color:#ffffff;background:{{ $verdeEscuro }};border-radius:6px;padding:6px 12px;">
                    Carrossel · {{ count($post->imagens_carrossel) }} imagens
                </td>
            @endif
        </tr></table>
    </td></tr>

    {{-- Capa do post --}}
    @if ($capa)
        <tr><td align="center" style="padding:22px 32px 0;">
            <img src="{{ $message->embed($capa) }}" width="400" alt="{{ $post->image_alt ?? $post->title }}"
                 style="display:block;width:400px;max-width:100%;height:auto;border:1px solid #dfe7e1;border-radius:8px;">
        </td></tr>
        <tr><td align="center" style="padding:8px 32px 0;font-family:{{ $fonte }};font-size:12px;color:{{ $cinza }};">
            {{ $anexos === 1 ? 'A imagem vai em anexo, em tamanho real.' : 'As '.$anexos.' imagens vão em anexo, em tamanho real e por ordem.' }}
        </td></tr>
    @endif

    {{-- Legenda --}}
    @if ($post->caption)
        <tr><td style="padding:22px 32px 0;">
            <div style="font-family:{{ $fonte }};font-size:12px;font-weight:700;letter-spacing:2px;color:{{ $cinza }};margin-bottom:8px;">LEGENDA</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="background:#f4f8f5;border-left:4px solid {{ $verde }};padding:16px 18px;font-family:{{ $fonte }};font-size:14px;line-height:21px;color:{{ $texto }};">
                    {!! nl2br(e($post->caption)) !!}
                </td>
            </tr></table>
        </td></tr>
    @endif

    {{-- Botões --}}
    @if ($aprovar)
        <tr><td align="center" style="padding:30px 32px 0;">
            {{-- Botões "à prova de Outlook": no Outlook para Windows desenhados em VML (tamanho fixo e cantos
                 redondos); nos outros programas, um link com o mesmo aspeto. --}}
            @php
                $botoes = [
                    ['url' => $aprovar, 'texto' => '✓ Aprovar', 'fundo' => $verde, 'borda' => $verde, 'cor' => '#ffffff'],
                    ['url' => $alterar, 'texto' => 'Pedir alteração', 'fundo' => '#ffffff', 'borda' => $verdeEscuro, 'cor' => $verdeEscuro],
                ];
            @endphp
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                @foreach ($botoes as $i => $b)
                    @if ($i)<td width="14" style="font-size:0;line-height:0;">&nbsp;</td>@endif
                    <td align="center">
                        <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $b['url'] }}"
                            style="height:50px;v-text-anchor:middle;width:210px;" arcsize="16%" strokecolor="{{ $b['borda'] }}" strokeweight="2px" fillcolor="{{ $b['fundo'] }}">
                            <w:anchorlock/>
                            <center style="color:{{ $b['cor'] }};font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $b['texto'] }}</center>
                        </v:roundrect>
                        <![endif]-->
                        <!--[if !mso]><!-- -->
                        <a href="{{ $b['url'] }}" target="_blank"
                           style="display:inline-block;width:206px;line-height:46px;text-align:center;background:{{ $b['fundo'] }};border:2px solid {{ $b['borda'] }};border-radius:8px;font-family:{{ $fonte }};font-size:16px;font-weight:700;color:{{ $b['cor'] }};text-decoration:none;-webkit-text-size-adjust:none;mso-hide:all;">{{ $b['texto'] }}</a>
                        <!--<![endif]-->
                    </td>
                @endforeach
            </tr></table>
        </td></tr>
        <tr><td align="center" style="padding:12px 40px 0;font-family:{{ $fonte }};font-size:12px;line-height:18px;color:{{ $cinza }};">
            Os botões abrem uma página para confirmar: abrir o link não decide nada.<br>
            Servem durante {{ $dias }} dias e só para esta versão do post.
        </td></tr>
    @endif

    <tr><td style="padding:28px 32px 0;"><div style="border-top:1px solid #e3e9e5;font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Rodapé --}}
    <tr><td align="center" style="padding:6px 32px 28px;font-family:{{ $fonte }};font-size:12px;line-height:19px;color:{{ $cinza }};">
        <a href="{{ $painel }}" target="_blank" style="color:{{ $verdeEscuro }};font-weight:700;text-decoration:none;">Abrir no painel</a>
        &nbsp;·&nbsp; O painel só abre na rede da NEXUS.<br>
        Nada é publicado sem aprovação de uma pessoa.
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
