<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ProfessorModel;
use App\Models\HorariosModel;
use App\Models\TemposAulasModel;
use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use CodeIgniter\Exceptions\ReferenciaException;

class Professor extends BaseController
{
    
    public function index()
    {
        // Cria a instância de um model do professor
        $professorModel = new ProfessorModel();
        $horariosModel = new HorariosModel();

        // Faz a busca por todos os professores cadastrado no banco (tabela professores)
        $data['professores'] = $professorModel->orderBy('nome', 'asc')->findAll();

        // Faz a busca por todos os horarios cadastrados no banco (tabela horarios)
        $data['horarios'] = $horariosModel->getHorariosAulas();
        
        
        // Exibe os professores cadastrados
        $data['content'] = view('sys/lista-professor', $data);
        return view('dashboard', $data);
    }

    public function cadastro(): string
    {
        // Exibe o formulário de cadastro
        $data['content'] = view('sys/cadastro-professor');
        return view('dashboard', $data);
    }

    public function professorPorId($id)
    {
        $professorModel = new ProfessorModel();
        $professor = $professorModel->find($id);

        return $this->response->setJSON($professor);
    }

    public function salvar()
    {
        $professor = new ProfessorModel();

        $data['professores'] = $professor->findAll();

        //coloca todos os dados do formulario no vetor dadosPost
        $dadosPost = $this->request->getPost();
        $dadosLimpos['nome'] = esc($dadosPost['nome']);
        $dadosLimpos['email'] = esc($dadosPost['email']);
        $dadosLimpos['siape'] = esc($dadosPost['siape'] ?? null);
        // //Verifica se o SIAPE esta vazio e poe NULL neste caso, pra não ir um SIAPE com valor 0 pro banco
        // if (empty($dadosPost['siape'])) {
        //     $dadosPost['siape'] = NULL;
        // }

        //tenta cadastrar o novo professor no banco
        if ($professor->insert($dadosLimpos)) {
            //se deu certo, direciona pra lista de professores
            session()->setFlashdata('sucesso', 'Professor cadastrado com sucesso.');
            return redirect()->to(base_url('/sys/professor')); // Redireciona para a página de listagem
        } else {
            $data['erros'] = $professor->errors(); //o(s) erro(s)
            return redirect()->to(base_url('/sys/professor'))->with('erros', $data['erros'])->withInput(); //retora com os erros e os inputs
        }
    }

    public function atualizar(){

        $dadosPost = $this->request->getPost();

        $dadosLimpos['id'] = strip_tags($dadosPost['id']);        
        $dadosLimpos['nome'] = strip_tags($dadosPost['nome']);
        $dadosLimpos['email'] = strip_tags($dadosPost['email']);

        $professorModel = new ProfessorModel();
        if($professorModel->save($dadosLimpos)){
            session()->setFlashdata('sucesso', 'Professor atualizado com sucesso.');
            return redirect()->to(base_url('/sys/professor')); // Redireciona para a página de listagem
        } else {
            $data['erros'] = $professorModel->errors(); //o(s) erro(s)
            return redirect()->to(base_url('/sys/professor'))->with('erros', $data['erros']); //retora com os erros
        }

    }

    public function deletar() {
        
        $dadosPost = $this->request->getPost();
        $id = strip_tags($dadosPost['id']);

        $professorModel = new ProfessorModel();
        try {
            if ($professorModel->delete($id)) {
                session()->setFlashdata('sucesso', 'Professor excluído com sucesso.');
                return redirect()->to(base_url('/sys/professor'));
            } else {
                return redirect()->to(base_url('/sys/professor'))->with('erro', 'Falha ao deletar professor');
            }
        } catch (ReferenciaException $e) {
            return redirect()->to(base_url('/sys/professor'))->with('erros', ['erro' => $e->getMessage()]);
        }
    }

    public function importar() {

        $file = $this->request->getFile('arquivo');

        if (!$file->isValid()) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setBody('Erro: Arquivo não enviado.');
        }

        $extension = $file->getClientExtension();
        if (!in_array($extension, ['xls', 'xlsx'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNSUPPORTED_MEDIA_TYPE)
                ->setBody('Erro: Formato de arquivo não suportado. Apenas XLSX ou XLS');
        }

        $reader = $extension === 'xlsx' ? new Xlsx() : new Xls();

        try {
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)
                ->setBody('Erro ao carregar o arquivo: ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getActiveSheet();
        $dataRows = [];

        $professorModel = new ProfessorModel();
        $data['professoresExistentes'] = [];

        foreach($professorModel->getProfessoresNome() as $k)
        {
            array_push($data['professoresExistentes'],$k['nome']);
        }

        // Lê os dados da planilha e captura Nome e E-mail
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue();
            }

            $dataRows[] = [
                'nome' => $rowData[1] ?? null,
                'email' => $rowData[4] ?? null,
            ];
        }

        // Remove cabeçalho
        array_shift($dataRows);

        // Exibe os dados lidos na view
        $data['professores'] = $dataRows;
        $data['content'] = view('sys/importar-professor-form', $data);
        return view('dashboard', $data);
    }

    public function processarImportacao() {

        $selecionados = $this->request->getPost('selecionados');

        if (empty($selecionados)) {
            session()->setFlashdata('erro', 'Nenhum registro selecionado para importação.');
            return redirect()->to(base_url('/sys/professor'));
        }

        $professorModel = new ProfessorModel();
        $insertedCount = 0;

        foreach ($selecionados as $registroJson) {
            $registro = json_decode($registroJson, true);

            if (!empty($registro['nome']) && !empty($registro['email'])) {
                $professorModel->insert($registro);
                $insertedCount++;
            }
        }

        session()->setFlashdata('sucesso', "{$insertedCount} registros importados com sucesso!");
        return redirect()->to(base_url('/sys/professor'));
    }

    public function preferencias($professorId) {
        $professorModel = new ProfessorModel();
        $temposAulaModel = new TemposAulasModel();

        $data['professor'] = $professorModel->find($professorId);
        // Faz a busca por todos os horarios cadastrados no banco (tabela horarios)
        $data['temposAula'] = $temposAulaModel->getTemposAulas();


        // Exibe os professores cadastrados
        $data['content'] = view('sys/preferencias-professor', $data);
        return view('dashboard', $data);
    }

    public function salvarRestricoes()
    {
        
    }
}
