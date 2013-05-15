<?php

defined('SYSPATH') or die('No direct script access.');

class Controller_Admin_Requests extends Controller_Admin_User {

    protected $model = 'request';

    public function before() {
        parent::before();
        if ($this->request->action() == 'archive') {
            $this->archive_class = 'class = "active"';
        } else {
            $this->requests_class = 'class = "active"';
        }
    }

    public function action_index() {
//        $this->get_pagination(ORM::Factory($this->model)->all(true));
        $orm                     = ORM::factory($this->model)->all();
        $this->template->content = view::factory('request/index')->set('orm', $orm);
    }

    public function action_view() {
        if ($this->id) {
            try {
                $orm = ORM::Factory($this->model)->one($this->id);
                if ($orm) {
                    if ($this->request->method() == Request::POST) {
                        $column = Arr::extract($_POST, array('column', 'value'));
                        try {
                            $old                     = $orm->$column['column'];
                            $orm->set($column['column'], $column['value'])
                                    ->set('view_date', date('Y-m-d H:i:s'))
                                    ->set('view_user', Auth::instance()->get_user()->id)
                                    ->set('view_user_role', Permissions::get_user_role_id())
                                    ->update();
                            $history                 = ORM::factory('history')->set('author_id', Auth::instance()->get_user()->id)
                                    ->set('request_id', $orm->id)
                                    ->set('column', $column['column'])
                                    ->set('old', $old)
                                    ->set('new', $column['value'])
                                    ->create();
                            $this->template->content = TRUE;
                        } catch (ORM_Validation_Exception $e) {
                            $this->template->content = FALSE;
                        } catch (HTTP_Exception_500 $e) {
                            $this->template->content = FALSE;
                        }
                    } else {
                        $orm->set('view_date', date('Y-m-d H:i:s'))
                                ->set('view_user', Auth::instance()->get_user()->id)
                                ->set('view_user_role', Permissions::get_user_role_id())
                                ->update();
                        $this->template->content = view::factory('request/view')->set('orm', $orm);
                    }
                } else {
                    $this->template->content = 'Такой заявки не существует.';
                }
            } catch (HTTP_Exception_500 $e) {
                $this->template->content = $e->getMessage();
            }
        } else {
            $this->request->redirect('requests');
        }
    }

    public function action_blacklist() {
        if ($this->id) {
            $orm = ORM::Factory($this->model)->one($this->id);
            $orm->set('status_id', Model_Request::STATUS_BLACKLIST)->update();
            Cache::instance('file')->delete('blacklist_json');
            $this->request->redirect('blacklist/view/' . $orm->id);
        } else {
            $this->request->redirect('requests');
        }
    }

    public function action_status() {
        if ($this->id) {
            $orm = ORM::Factory('request', $this->id);
            if (Permissions::instance()->is_allowed($this->role, 'requests', 'from_' . $orm->status_id . '_to_' . $this->status_array[$this->request->param('status')]) OR Auth::instance()->logged_in('admin')) {
                if ($this->request->param('status') == 'pay') {
                    if ($this->request->method() == Request::POST) {
                        $keys = array('due', 'procents');
                        $data                   = Arr::extract($_POST, $keys);
                        array_map('intval', $data);
                        $data['interest_rate']  = $orm->summ * $data['procents'] * intval($data['due']) * 0.01;
                        $data['repayment_summ'] = $data['interest_rate'] + $orm->summ;
                        $date                   = new DateTime();
                        $date                   = $date->modify('+' . $data['due'] . ' DAY')->format('Y-m-d');
                        try {
                            $old = $orm->status_id;
                            if ($old = Model_Request::STATUS_PAY AND $orm->loan->loaded()) {
                                $this->request->redirect('loans/view/' . $orm->loan->id);
                            } else {
                                $orm->set('status_id', Model_Request::STATUS_PAY)
                                        ->update();
                            }
                            $history = ORM::factory('history')->set('author_id', Auth::instance()->get_user()->id)
                                    ->set('request_id', $orm->id)
                                    ->set('column', 'status_id')
                                    ->set('old', Model_Request::STATUS_APPROVE)
                                    ->set('new', MOdel_Request::STATUS_PAY)
                                    ->create();
                            $loan    = ORM::factory('loans')
                                    ->values($data)
                                    ->set('summ', $orm->summ)
                                    ->set('request_id', $orm->id)
                                    ->set('repayment_date', $date)
                                    ->set('status_id', Model_Loans::STATUS_CURRENT)
                                    ->create();
                            $this->request->redirect('loans/view/' . $loan->id);
                        } catch (ORM_Validation_Exception $e) {
                            $this->send_errors($e);
                        }
                    } else {
                        $loan = $orm->loan;
                        if ($loan->loaded()) {
                            $this->request->redirect('loans');
                        } else {
                            $this->template->content = view::factory('loans/loan_dialog')->set('id', $orm->id);
                        }
                    }
                } else {
                    $old     = $orm->status_id;
                    $orm->set('status_id', Model_Request::status($this->request->param('status')))
//                        ->set('status_changed', 1)
                            ->update();
                    $history = ORM::factory('history')->set('author_id', Auth::instance()->get_user()->id)
                            ->set('request_id', $orm->id)
                            ->set('column', 'status_id')
                            ->set('old', $old)
                            ->set('new', $orm->status_id)
                            ->create();
                    $this->request->redirect('requests/view/' . $orm->id);
                }
            } else {
                $this->template->content = 'Нет доступа';
            }
        } else {
            $this->request->redirect('requests');
        }
    }

    public function action_archive() {
        if (Auth::instance()->logged_in('admin')) {
            if ($this->request->method() == Request::POST) {
                //require 'MySQLBackup.php';
                $this->template       = FALSE;
                $this->auto_render    = FALSE;
                $backup               = MySQLBackup::factory();
                $backup->fname_format = 'Y-m-d H:i:s';
                // Кодировка базы
                $backup->characters   = 'UTF8';
                // Место куда будем заливать дамп. Не забываем про слеши.
                $backup->backup_dir   = '../motaCMS/backup/';
                //   $this->response->headers('Content-Type', File::mime_by_ext($ext));
//                $this->response->headers('Content-Length', (string) filesize($file));
//                $this->response->headers('Last-Modified', date('r', filemtime($file)));
                $this->response->body($backup->Execute());
//                $dir                  = scandir('../motaCMS/backup');
//                foreach ($dir as $file) {
//                    if($file!='.' AND $file!='..')
//                            $sql = $file;
//                }
//                
//                $this->response->send_file($sql);
//                Header('Content-Type: application/octet-stream');
//                Header('Content-disposition: attachment; filename=backup.sql');
                //;
                // echo file_get_contents($path.$filename) ;
            } else {
                $this->get_pagination(ORM::Factory($this->model)->all(true));
                $orm                     = ORM::factory($this->model)->all();
                $this->template->content = view::factory('request/archive')->set('orm', $orm);
            }
        }
    }

    public function action_new() {
        if ($this->request->method() == Request::POST) {
            $data                       = Arr::get($_POST, 'Request');
            $data['created_by_user_id'] = Auth::instance()->get_user()->id;
            try {
                $orm = ORM::Factory('request')->values($data)->create();
                ORM::factory('history')->set('column', 'status_id')
                        ->set('author_id', Auth::instance()->get_user()->id)
                        ->set('request_id', $orm->id)
                        ->set('new', Model_Request::STATUS_NEW)
                        ->create();
                $this->request->redirect('requests/view/' . $orm->id);
            } catch (ORM_Validation_Exception $e) {
                $this->send_errors($e);
            } catch (HTTP_Exception_500 $e) {
                $this->template->content = $e->get_message();
            }
        } else {
            $this->template->content = view::factory('request/new');
        }
    }

    public function action_send_comment() {
        if ($this->id) {
            $request = ORM::Factory('request', $this->id);
            if (Permissions::instance()->is_allowed($this->role, 'comments', 'send') AND Permissions::instance()->is_allowed($this->role, 'requests', 'view_' . $request->status_id)) {
                ORM::factory('comment')->set('comment', Arr::get($_POST, 'comment'))
                        ->set('user_id', Auth::instance()->get_user()->id)
                        ->set('request_id', $this->id)
                        ->set('user_role_id', Permissions::get_user_role_id())
                        ->create();
                $this->request->redirect('requests/view/' . $request->id);
            } else {
                $this->template->content = 'Нет доступа';
            }
        }
    }

    public function action_download_request() {
        if (Permissions::instance()->is_allowed($this->role, 'instruments', 'download_request')) {
            try {
                $orm  = ORM::factory('request')->one($this->id);
                $mpdf = new mPDF('UTF-8', 'A4');
                $pdf  = View::factory('request/pdf/request_html_to_pdf', array('orm'   => $orm, 'print' => NULL))->render();
                $footer = html_entity_decode('Подпись клиента            ______________', ENT_QUOTES, 'UTF-8');
                $mpdf->SetHTMLFooter($footer);
                $mpdf->WriteHTML($pdf);
                $mpdf->output('Заявка №' . $orm->id . '.pdf', 'D');
            } catch (HTTP_Exception_500 $e) {
                $this->template->content = 'Нет доступа к этой заявке';
            }
        } else {
            $this->template->content = 'Нет доступа';
        }
    }

    public function action_download_contract() {
        if (Permissions::instance()->is_allowed($this->role, 'instruments', 'download_contract')) {
            try {
                $orm = ORM::factory('request')->one($this->id);
                $pdf = View_mPDF::factory('request/pdf/contract_html_to_pdf', array('orm'   => $orm, 'print' => NULL));
                $pdf->download('Договор №' . $this->id . '.pdf');
            } catch (HTTP_Exception_500 $e) {
                $this->template->content = 'Нет доступа к этой заявке';
            }
        } else {
            $this->template->content = 'Нет доступа';
        }
    }

    public function action_print_request() {

        if (Permissions::instance()->is_allowed($this->role, 'instruments', 'print_request')) {
            try {
                $this->template = FALSE;
                $orm            = ORM::factory($this->model)->one($this->id);
//                $mpdf           = new mPDF('UTF-8', 'A4');
//                $pdf            = View::factory('request/pdf/request_html_to_pdf', array('orm'   => $orm, 'print' => TRUE))->render();
//                $footer = html_entity_decode('Подпись клиента            ______________', ENT_QUOTES, 'UTF-8');
//                $mpdf->SetHTMLFooter($footer);
//                $mpdf->WriteHTML($pdf);
//                header('Content-Type: application/pdf');
//                if (headers_sent())
//                    $this->Error('Some data has already been output to browser, can\'t send PDF file');
//                if (!isset($_SERVER['HTTP_ACCEPT_ENCODING']) OR empty($_SERVER['HTTP_ACCEPT_ENCODING'])) {
//                    // don't use length if server using compression
//                    header('Content-Length: ' . strlen($this->buffer));
//                }
//                header('Content-disposition: inline; filename="' . $name . '"');
//                header('Cache-Control: public, must-revalidate, max-age=0');
//                header('Pragma: public');
//                header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
//                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
//                $finish         = $mpdf->output('Заявка №' . $orm->id . '.pdf', 'I');
                $this->template = View::factory('request/pdf/request_html_to_pdf', array('orm'   => $orm, 'print' => TRUE));
//                $this->template = $finish;
            } catch (HTTP_Exception_500 $e) {
                $this->template->content = 'Нет доступа к этой заявке';
            }
        } else {
            $this->template->content = 'Нет доступа';
        }
    }

    public function action_print_contract() {
        if (Permissions::instance()->is_allowed($this->role, 'instruments', 'print_contract') AND $this->id) {
            $orm = ORM::factory('request')->one($this->id);
            if ($this->request->method() == Request::POST) {
                if ($orm) {
                    if (isset($_POST['column'])) {
                        $column = Arr::extract($_POST, array('column', 'value'));
                        if ($column['column'] == 'procents') {
                            try {
                                $orm->loan->set('procents', $column['value'])->update();
                            } catch (ORM_Validation_Exception $e) {
                                $this->template->content = FALSE;
                            }
                        } else {
                            try {
                                $old                     = $orm->$column['column'];
                                $orm->set($column['column'], $column['value'])
                                        ->set('view_date', date('Y-m-d H:i:s'))
                                        ->set('view_user', Auth::instance()->get_user()->id)
                                        ->set('view_user_role', Permissions::get_user_role_id())
                                        ->update();
                                $history                 = ORM::factory('history')->set('author_id', Auth::instance()->get_user()->id)
                                        ->set('request_id', $orm->id)
                                        ->set('column', $column['column'])
                                        ->set('old', $old)
                                        ->set('new', $column['value'])
                                        ->create();
                                $this->template->content = TRUE;
                            } catch (ORM_Validation_Exception $e) {
                                $this->template->content = FALSE;
                            } catch (HTTP_Exception_500 $e) {
                                $this->template->content = FALSE;
                            }
                        }
                    } else {
                        $this->template = View::factory('request/pdf/contract_html_to_pdf_with_addition', array('orm'   => $orm, 'print' => TRUE));
                    }
                } else {
                    $this->template->content = 'Такой заявки не существует.';
                }
            } else {
                $this->template->content = view::factory('request/contract_dialog', array('orm' => $orm, 'id'  => $this->id));
            }
        } else {
            $this->template->content = 'Нет доступа';
        }
    }

}

