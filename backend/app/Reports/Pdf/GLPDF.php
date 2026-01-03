<?php

namespace App\Reports\Pdf;

use TCPDF;

class GLPDF extends TCPDF
{
    /** @var array{name:string,tin:string,addr1:string,addr2:string} */
    protected array $companyHeader = [
        'name'  => 'SUCDEN PHILIPPINES, INC.',
        'tin'   => 'TIN-000-105-2567-000',
        'addr1' => 'Unit 2202 The Podium West Tower',
        'addr2' => 'Ortigas Center, Mandaluyong City',
    ];

    /**
     * Call this after creating the PDF instance.
     * Example: $pdf->setCompanyHeader($cid);
     */
    public function setCompanyHeader(int $companyId): void
    {
        // Default (company_id != 2)
        $this->companyHeader = [
            'name'  => 'SUCDEN PHILIPPINES, INC.',
            'tin'   => 'TIN-000-105-2567-000',
            'addr1' => 'Unit 2202 The Podium West Tower',
            'addr2' => 'Ortigas Center, Mandaluyong City',
        ];

        // Company 2 override
        if ($companyId === 2) {
            $this->companyHeader = [
                'name'  => 'AMEROP PHILIPPINES, INC.',
                'tin'   => 'TIN- 762-592-927-000',
                'addr1' => 'Com. Unit 301-B Sitari Bldg., Lacson St. cor. C.I Montelibano Ave.,',
                'addr2' => 'Brgy. Mandalagan, Bacolod City',
            ];
        }
    }

    // Header
    public function Header(): void
    {
        $name  = htmlspecialchars($this->companyHeader['name']  ?? '', ENT_QUOTES, 'UTF-8');
        $tin   = htmlspecialchars($this->companyHeader['tin']   ?? '', ENT_QUOTES, 'UTF-8');
        $addr1 = htmlspecialchars($this->companyHeader['addr1'] ?? '', ENT_QUOTES, 'UTF-8');
        $addr2 = htmlspecialchars($this->companyHeader['addr2'] ?? '', ENT_QUOTES, 'UTF-8');

        $this->SetY(10);

        $html = <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td align="right">
      <font size="12"><b>{$name}</b></font><br/>
      {$tin}<br/>
      {$addr1}<br/>
      {$addr2}
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
