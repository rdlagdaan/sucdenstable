<?php

namespace App\Reports\Pdf;

use TCPDF;

class GLPDF extends TCPDF
{
    // Header
    public function Header(): void
    {
        $this->SetY(10);
        $html = <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td align="right">
      <font size="12"><b>SUCDEN PHILIPPINES, INC.</b></font><br/>
      TIN-000-105-2567-000<br/>
      Unit 2202 The Podium West Tower<br/>
      Ortigas Center, Mandaluyong City
    </td>
  </tr>
  <tr><td><hr/></td></tr>
</table>
HTML;
        $this->writeHTML($html, true, false, false, false, '');
    }

    // Footer
    public function Footer(): void
    {
        $this->SetY(-20);
        $this->SetFont('helvetica', 'I', 8);

        $currentDate = date('M d, Y');
        $currentTime = date('h:i:sa');

        $pageNo = $this->getAliasNumPage().'/'.$this->getAliasNbPages();

        $html = <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td><font size="8">Print Date:</font></td>
    <td><font size="8">{$currentDate}</font></td>
    <td></td>
    <td></td>
  </tr>
  <tr>
    <td><font size="8">Print Time:</font></td>
    <td><font size="8">{$currentTime}</font></td>
    <td></td>
    <td><font size="8">{$pageNo}</font></td>
  </tr>
</table>
HTML;
        $this->writeHTML($html, true, false, false, false, '');
    }
}
