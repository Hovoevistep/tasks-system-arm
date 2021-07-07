<?php

namespace App\Http\Controllers;

use App\Models\Boards;
use App\Models\Cards;
use App\Models\IntegratedBoards;
use App\Models\IntegratedCards;
use App\Models\IntegratedLists;
use App\Models\Lists;
use App\Models\TrelloCredential;
use App\Models\User;
use Unirest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function GuzzleHttp\Promise\all;

class TestController extends Controller
{

    public function settings(Request $request)
    {
        $headers = array(
            'Accept' => 'application/json'
        );

        $key = $request->get('key');
        $token = $request->get('token');

        $query = array(
            'key' => $key,
            'token' => $token
        );

        $response = Unirest\Request::get(
            'https://api.trello.com/1/members/me/boards?key='. $key .'&token=' .$token,
            $headers,
            $query
        );


        if($response->code === 200){

            TrelloCredential::updateOrCreate(
                [
                    'user_id' => Auth::user()->id,
                    'key'     => $key,
                    'token'   => $token
                ]
            );
        }
        else
        {
            session()->flash('error', 'Please send normal data');
            return redirect()->back();
        }

        session()->flash('success', 'Your trello is connected');
        return redirect()->to('home');

    }


    public function importing(Request $request)
    {

        if(!empty(Auth::user()->trelloCredentials))
        {
            $headers = array(
                'Accept' => 'application/json'
            );

            $key = Auth::user()->trelloCredentials->first()->key;
            $token = Auth::user()->trelloCredentials->first()->token;

            $query = array(
                'key' => $key,
                'token' => $token
            );

            $response = Unirest\Request::get(
                'https://api.trello.com/1/members/me/boards?key='. $key .'&token=' .$token,
                $headers,
                $query
            );

            $user  = Auth::user();

            $boards = $response->body;

            foreach($boards as $key => $board)
            {

                $backgroundBottomColor = $board->prefs->backgroundBottomColor;
                $backgroundTopColor = $board->prefs->backgroundTopColor;
                $backgroundImage = $board->prefs->backgroundImage;

                if(empty(IntegratedBoards::where('trello_board_id', $board->id)->first()))
                {

                    $createBoards = Boards::create(
                        [
                            'name'                  => $board->name,
                            'url'                   => $board->url,
                            'short_url'             => $board->shortUrl,
                            'backgroundBottomColor' => $backgroundBottomColor,
                            'backgroundTopColor'    => $backgroundTopColor,
                            'backgroundImage'       => $backgroundImage,
                            'desc'                  => $board->desc
                        ]
                    );
                    IntegratedBoards::create(
                        [
                            'user_id'         => $user->id,
                            'board_id'        => $createBoards->id,
                            'trello_board_id' => $board->id
                        ]
                    );
                }

                else
                {

                    $createBoards = Boards::where('id', IntegratedBoards::where('trello_board_id', $board->id)
                                    ->pluck('board_id'))
                                    ->update([
                                        'name'                  => $board->name,
                                        'url'                   => $board->url,
                                        'short_url'             => $board->shortUrl,
                                        'backgroundBottomColor' => $backgroundBottomColor,
                                        'backgroundTopColor'    => $backgroundTopColor,
                                        'backgroundImage'       => $backgroundImage,
                                        'desc'                  => $board->desc
                                    ]);
                }


                $responseList = Unirest\Request::get(
                    'https://api.trello.com/1/boards/'. $board->id .'/lists?key='. $key .'&token=' .$token,
                    $headers,
                    $query
                );




                foreach($responseList->body as $val => $list)
                {

                    $board_id = IntegratedBoards::where('trello_board_id', $list->idBoard)->pluck('board_id')->first();

                    if(empty(IntegratedLists::where('trello_list_id', $list->id)->first()))
                    {

                        $createLists = Lists::create(
                            [
                                'name'    => $list->name,
                            ]
                        );


                    }

                   else
                   {
                        $createLists = Lists::where('id', IntegratedLists::where('trello_list_id', $list->id)
                                       ->pluck('list_id'))
                                       ->update(
                                            [
                                                'name'    => $list->name,
                                            ]
                                        );
                   }

                   $integratedList = IntegratedLists::where('trello_list_id', $list->id);

                   if($integratedList->count())
                   {

                      $intListId = Lists::where('id', IntegratedLists::where('trello_list_id', $list->id)->pluck('list_id'))->first();

                      $integratedList->update(
                           [
                               'board_id'  => $board_id,
                               'list_id'   => $intListId->id
                           ]);
                   }
                   else
                   {
                        IntegratedLists::create(
                            [
                                'board_id'        => $board_id,
                                'list_id'         => $createLists->id,
                                'trello_list_id'  => $list->id
                            ]
                        );
                    }
                }

                $responseCard = Unirest\Request::get(
                    'https://api.trello.com/1/boards/'. $board->id .'/cards?key='. $key .'&token=' .$token,
                    $headers,
                    $query
                );

                foreach($responseCard->body as $card)
                {
                    $list_id = IntegratedLists::where('trello_list_id', $card->idList)->pluck('list_id')->first();

                    if(empty(IntegratedCards::where('trello_card_id', $card->id)->first()))
                    {
                        $createCards = Cards::create(
                            [
                                'name'         => $card->name,
                                'short_url'    => $card->shortUrl,
                                'desc'         => $card->desc,
                                'idAttachment' => $card->cover->idAttachment
                            ]
                        );

                    }
                    else
                    {
                        $createCards = Cards::where('id', IntegratedCards::where('trello_card_id', $card->id)
                        ->pluck('card_id'))
                        ->update(
                            [
                                'name'         => $card->name,
                                'short_url'    => $card->shortUrl,
                                'desc'         => $card->desc,
                                'idAttachment' => $card->cover->idAttachment
                            ]
                        );

                    }

                    $integratedCard = IntegratedCards::where('trello_card_id', $card->id);

                    if($integratedCard->count())
                    {
                        $intCardId = IntegratedCards::where('trello_card_id', $card->id)->first()->id;

                        $integratedCard->update(
                            [
                                'list_id'  => $list_id,
                                'card_id'  => $intCardId
                            ]);
                    }
                    else
                    {
                        IntegratedCards::create(
                            [
                                'list_id'        => $list_id,
                                'card_id'        => $createCards->id,
                                'trello_card_id' => $card->id
                            ]

                        );
                    }
                }
            }

        }else{
            session()->flash('error', 'Empty your information');
            return redirect()->back();
    }

       return response()->json(200);
    }


    public function boards()
    {
        $boards = IntegratedBoards::join('boards', 'boards.id', '=', 'integrated_boards.board_id')->get();

        return view('boards', compact('boards'));
    }

    public function view($id)
    {
        $board = IntegratedBoards::where('boards.id', $id)
                 ->join('boards', 'boards.id', '=', 'integrated_boards.board_id')
                 ->first();

        if($board === null){
            return redirect()->to('/errorPage');
        }

        return view('view', compact('board'));
    }

    public function viewList($id)
    {

        $board = IntegratedBoards::where('boards.id', $id)
                 ->join('boards', 'boards.id', '=', 'integrated_boards.board_id')
                 ->first();

        $lists = IntegratedLists::where('integrated_lists.board_id', $id)
                 ->leftJoin('lists', 'lists.id', '=' , 'integrated_lists.list_id')
                 ->get();


        if($board === null){
            return redirect()->to('/errorPage');
         }

        return view('lists', compact('board', 'lists'));
    }

    public function viewCards($id, $listId){

        $board = IntegratedBoards::where('boards.id', $id)
                 ->join('boards', 'boards.id', '=', 'integrated_boards.board_id')
                 ->first();

        $list = IntegratedLists::where('list_id', $listId)
        ->where('board_id', $id)
        ->join('boards', 'boards.id', '=', 'integrated_lists.board_id')
        ->join('lists', 'lists.id', '=', 'integrated_lists.list_id')
        ->first();



        if($board === null ||empty($list->integratedCardsFromLists)){
            return redirect()->to('/errorPage');
        }

        $cards = $list->integratedCardsFromLists;

        return view('cards', compact('board', 'cards'));
    }

    public function viewCardPage($id,$listId,$cardId)
    {
        $board = IntegratedBoards::where('boards.id', $id)
        ->join('boards', 'boards.id', '=', 'integrated_boards.board_id')
        ->first();
            
        $card = IntegratedCards::where('list_id', $listId)
        ->where('card_id', $cardId)
        ->join('lists', 'lists.id', '=', 'integrated_cards.list_id')
        ->join('cards', 'cards.id', '=' , 'integrated_cards.card_id')
        ->first();

        if($board === null || $card === null){
            return redirect()->to('/errorPage');
        }


        return view('viewCard', compact('card', 'board'));
    }


    public function import()
    {
        return view('import');
    }

    public function dashboard()
    {
        return view('dashboard');
    }


    public function errorPage()
    {
        return view('error');
    }

}
